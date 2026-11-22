<?php
declare(strict_types=1);

namespace Database\Tools;

use App\Auth\Repositories\MockUserRepository;
use App\Auth\Repositories\MySQLUserRepository;
use App\Auth\Repositories\UserTransactionInterface;
use App\Core\JsonStore;
use App\Enrollment\Repositories\MockCatalogRepository;
use App\Enrollment\Repositories\MySQLCatalogRepository;
use App\Students\Repositories\JsonProgramCatalog;
use App\Students\Repositories\MockStudentRepository;
use App\Students\Repositories\MySQLProgramCatalog;
use App\Students\Repositories\MySQLStudentRepository;
use PDO;
use RuntimeException;
use Throwable;

final class ImportRefused extends RuntimeException {}

/** Single-use import into an empty ais_test module, with validation before commit. */
final class JsonImporter
{
    private const TABLES = [
        'auth'=>['users','user_roles','audit_logs'],
        'students'=>['programs','students','student_academic_history'],
        'enrollment'=>['terms','sections','section_meetings','section_instructor_history','section_instructors'],
    ];
    private const SOURCE_FILES = [
        'auth'=>['auth/users.json'],
        'students'=>['students/students.json','students/programs.json'],
        'enrollment'=>['enrollment/enrollment.json'],
    ];

    public function __construct(private readonly PDO $pdo, private readonly string $migrations) {}

    /** @return array{module:string,runtime:string,target:string,dry_run:bool} */
    public static function parseArguments(array $argv, ?string $envFile = null): array
    {
        $options = [];
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--dry-run' && !isset($options['dry_run'])) { $options['dry_run'] = true; continue; }
            if (preg_match('/\A--(module|runtime|target)=(.*)\z/D', $arg, $match) && !isset($options[$match[1]])) {
                $options[$match[1]] = $match[2]; continue;
            }
            throw new ImportRefused('Import arguments refused.');
        }
        if (!isset($options['module'], $options['runtime'], $options['target'])
            || $options['runtime'] === '' || str_contains($options['runtime'], "\0")
            || str_contains($options['runtime'], '://')) {
            throw new ImportRefused('Import arguments refused.');
        }
        if ($options['module'] === 'faculty') { throw new ImportRefused('Faculty import deferred until T-30.'); }
        if (!array_key_exists($options['module'], self::TABLES) || $options['target'] !== 'ais_test') {
            throw new ImportRefused('Target or module refused.');
        }
        if (preg_match('~\A[\\\\/]{2}~', $options['runtime'])) {
            throw new ImportRefused('Runtime refused.');
        }
        $runtime = realpath($options['runtime']);
        $absolutePath = '~\A(?:[A-Za-z]:)?[\x2F\x5C]~';
        if ($runtime === false || !is_dir($runtime)
            || !preg_match($absolutePath, $options['runtime']) || !preg_match($absolutePath, $runtime)) {
            throw new ImportRefused('Runtime refused.');
        }
        $forbidden = ['C:/ais-runtime', dirname(__DIR__, 2) . '/storage/runtime'];
        $envFile ??= dirname(__DIR__, 2) . '/.env';
        if (is_file($envFile)) {
            $lines = @file($envFile, FILE_IGNORE_NEW_LINES);
            if ($lines === false) { throw new ImportRefused('Runtime refused.'); }
            foreach ($lines as $line) {
                if (preg_match('/\A\s*APP_RUNTIME_PATH\s*=\s*(.*?)\s*\z/D', $line, $match)) {
                    $forbidden[] = trim($match[1], "\"'");
                }
            }
        }
        foreach ($forbidden as $path) {
            $resolved = realpath($path);
            if ($resolved !== false && $resolved === $runtime) { throw new ImportRefused('Runtime refused.'); }
        }
        self::assertSourceFiles($options['module'], $runtime);
        return ['module'=>$options['module'], 'runtime'=>$runtime, 'target'=>$options['target'],
            'dry_run'=>$options['dry_run'] ?? false];
    }

    public static function assertOfflineTarget(array $options, array $config): void
    {
        if (($options['target'] ?? '') !== 'ais_test' || ($config['database'] ?? '') !== 'ais_test'
            || !in_array($config['host'] ?? '', ['127.0.0.1','localhost','::1'], true)
            || !is_string($config['port'] ?? null) || !ctype_digit($config['port'])
            || (int) $config['port'] < 1 || (int) $config['port'] > 65535
            || ($config['username'] ?? '') !== 'ais_tester') {
            throw new ImportRefused('Target or migration account refused.');
        }
    }

    /** @return array<string,int> */
    public function import(array $options): array
    {
        self::assertSourceFiles($options['module'], $options['runtime']);
        $this->assertLiveTarget();
        $this->assertSchema();
        $module = $options['module'];
        $runtime = $options['runtime'];
        $hashes = self::sourceHashes($module, $runtime);
        $raw = $this->rawSource($module, $runtime);
        $snapshot = self::snapshotRuntime($module, $raw);
        try {
        $this->validateSource($module, $snapshot, $raw);
        $this->assertCleanTarget();
        $this->pdo->beginTransaction();
        try {
            $lock = $this->statement('SELECT last_seq FROM repository_locks WHERE name = ? FOR UPDATE', [$module])->fetchColumn();
            if ($lock === false || (int) $lock !== 0) { throw new ImportRefused('Module already imported.'); }
            $this->assertTablesEmpty($module);
            $this->assertDependencies($module, $raw);
            $counts = match ($module) {
                'auth'=>$this->insertAuth($raw),
                'students'=>$this->insertStudents($raw),
                'enrollment'=>$this->insertEnrollment($raw),
            };
            foreach ($counts as $table => $expected) {
                if ((int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== $expected) {
                    throw new RuntimeException('Import count mismatch.');
                }
            }
            $this->assertEquivalent($module, $snapshot, $raw);
            $checks = (new IntegrityVerifier($this->pdo))->verify();
            foreach ($checks as $ids) { if ($ids !== []) { throw new RuntimeException('Import integrity check failed.'); } }
            if (self::sourceHashes($module, $runtime) !== $hashes) {
                throw new RuntimeException('Source changed during import.');
            }
            if ($options['dry_run']) { $this->pdo->rollBack(); }
            else { $this->pdo->commit(); }
            return $counts;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
        } finally { self::removeSnapshot($snapshot); }
    }

    private static function assertSourceFiles(string $module, string $runtime): void
    {
        foreach (self::SOURCE_FILES[$module] as $file) {
            if (!is_file($runtime . '/' . $file) || !is_file($runtime . '/' . $file . '.lock')) {
                throw new ImportRefused('Runtime refused.');
            }
        }
    }

    private static function sourceHashes(string $module, string $runtime): array
    {
        $hashes = [];
        foreach (self::SOURCE_FILES[$module] as $file) {
            $lock = @fopen($runtime . '/' . $file . '.lock', 'rb');
            if ($lock === false) { throw new RuntimeException('Unable to hash source.'); }
            try {
                if (!flock($lock, LOCK_SH)) { throw new RuntimeException('Unable to hash source.'); }
                $hash = @hash_file('sha256', $runtime . '/' . $file);
                if ($hash === false) { throw new RuntimeException('Unable to hash source.'); }
                $hashes[$file] = $hash;
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }
        return $hashes;
    }

    private static function snapshotRuntime(string $module, array $raw): string
    {
        $root = sys_get_temp_dir() . '/ais-import-' . bin2hex(random_bytes(12));
        if (!mkdir($root, 0700)) { throw new RuntimeException('Unable to create snapshot.'); }
        try {
            foreach (self::SOURCE_FILES[$module] as $file) {
                $directory = $root . '/' . dirname($file);
                if (!is_dir($directory) && !mkdir($directory, 0700)) { throw new RuntimeException('Unable to create snapshot.'); }
                $data = $module === 'students' ? $raw[basename($file, '.json')] : $raw;
                if (file_put_contents($root . '/' . $file, json_encode($data, JSON_THROW_ON_ERROR)) === false
                    || file_put_contents($root . '/' . $file . '.lock', '') === false) {
                    throw new RuntimeException('Unable to create snapshot.');
                }
            }
            return $root;
        } catch (Throwable $error) { self::removeSnapshot($root); throw $error; }
    }

    private static function removeSnapshot(string $root): void
    {
        $directories = [];
        foreach (self::SOURCE_FILES as $files) foreach ($files as $file) {
            if (is_file($root . '/' . $file)) { unlink($root . '/' . $file); }
            if (is_file($root . '/' . $file . '.lock')) { unlink($root . '/' . $file . '.lock'); }
            $directories[$root . '/' . dirname($file)] = true;
        }
        foreach (array_keys($directories) as $directory) { if (is_dir($directory)) { rmdir($directory); } }
        if (is_dir($root)) { rmdir($root); }
    }

    private function assertLiveTarget(): void
    {
        $row = $this->pdo->query('SELECT DATABASE() AS db_name, @@port AS server_port, CURRENT_USER() AS account')->fetch();
        $port = \App\Core\Environment::get('DB_PORT', '');
        if (!is_array($row) || $row['db_name'] !== 'ais_test' || (string)$row['server_port'] !== $port
            || !str_starts_with((string)$row['account'], 'ais_tester@')) {
            throw new ImportRefused('Live target refused.');
        }
    }

    private function assertSchema(): void
    {
        try {
            $files = MigrationRunner::discoverFiles($this->migrations);
            $rows = $this->pdo->query('SELECT version,name,checksum,state,statements_applied FROM schema_migrations ORDER BY version')->fetchAll();
            MigrationRunner::validateHistory($files, $rows);
            if (count($rows) !== count($files)) { throw new ImportRefused('Schema not fully applied.'); }
        } catch (Throwable) {
            throw new ImportRefused('Schema not fully applied.');
        }
    }

    private function assertCleanTarget(): void
    {
        foreach ((new IntegrityVerifier($this->pdo))->verify() as $ids) {
            if ($ids !== []) { throw new ImportRefused('Target integrity check failed.'); }
        }
    }

    private function assertTablesEmpty(string $module): void
    {
        foreach (self::TABLES[$module] as $table) {
            if ((int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) {
                throw new ImportRefused('Module target is not empty.');
            }
        }
    }

    private function assertDependencies(string $module, array $raw): void
    {
        if ($module === 'auth') { return; }
        if ((int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
            throw new ImportRefused('Auth dependency missing.');
        }
        if ($module === 'enrollment') {
            $facultyIds = [];
            foreach (array_merge($raw['assignments'] ?? [], $raw['assignment_history'] ?? []) as $entry) {
                $facultyIds[(int) $entry['faculty_id']] = true;
            }
            $query = $this->pdo->prepare('SELECT id FROM faculty WHERE id = ?');
            foreach (array_keys($facultyIds) as $id) {
                $query->execute([$id]);
                if ($query->fetchColumn() === false) { throw new RuntimeException('Orphan faculty id.'); }
            }
        }
    }

    private function validateSource(string $module, string $runtime, array $raw): void
    {
        if ($module === 'auth') {
            $mock = new MockUserRepository(new JsonStore($runtime . '/auth/users.json'));
            $mock->findById(1); $mock->auditEntries();
        } elseif ($module === 'students') {
            $mock = new MockStudentRepository(new JsonStore($runtime . '/students/students.json'));
            $mock->findById(1); $mock->history(1, 1, 1);
            (new JsonProgramCatalog(new JsonStore($runtime . '/students/programs.json')))->all();
        } else {
            (new MockCatalogRepository(new JsonStore($runtime . '/enrollment/enrollment.json')))->terms();
        }
        $this->validateRaw($module, $raw);
    }

    private function rawSource(string $module, string $runtime): array
    {
        return match ($module) {
            'auth'=>self::readSourceFile($runtime . '/auth/users.json'),
            'students'=>['students'=>self::readSourceFile($runtime . '/students/students.json'),
                'programs'=>self::readSourceFile($runtime . '/students/programs.json')],
            'enrollment'=>self::readSourceFile($runtime . '/enrollment/enrollment.json'),
        };
    }

    private static function readSourceFile(string $path): array
    {
        $lock = @fopen($path . '.lock', 'rb');
        if ($lock === false) { throw new RuntimeException('Unable to read source.'); }
        try {
            if (!flock($lock, LOCK_SH)) { throw new RuntimeException('Unable to read source.'); }
            $contents = @file_get_contents($path);
            if ($contents === false) { throw new RuntimeException('Unable to read source.'); }
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) { throw new RuntimeException('Invalid source.'); }
            return $data;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function validateRaw(string $module, array $data): void
    {
        if ($module === 'auth') {
            self::onlyKeys($data, ['users','audit']);
            $users = self::list($data['users'] ?? []); $audit = self::list($data['audit'] ?? []);
            $ids = [];
            foreach ($users as $row) {
                if (!is_array($row) || !is_int($row['id'] ?? null) || ($row['email'] ?? null) !== strtolower(trim((string)($row['email'] ?? '')))) {
                    throw new RuntimeException('Invalid source user.');
                }
                $ids[$row['id']] = true;
            }
            foreach ($audit as $index => $row) {
                if (!is_array($row) || array_keys($row) !== ['id','at','actor_id','target_id','action','outcome','changes']
                    || $row['id'] !== $index + 1 || !self::validTime($row['at'])
                    || !is_int($row['actor_id']) || !isset($ids[$row['actor_id']])
                    || !is_int($row['target_id']) || $row['target_id'] < 0
                    || !is_string($row['action']) || $row['action'] === ''
                    || !in_array($row['outcome'], ['success','denied'], true)
                    || !is_array($row['changes'])
                    || array_diff(array_keys($row['changes']), UserTransactionInterface::AUDIT_KEYS) !== []
                    || self::hasSecret($row['changes'])) {
                    throw new RuntimeException('Invalid source audit.');
                }
            }
        } elseif ($module === 'students') {
            self::onlyKeys($data['students'], ['students','history']);
            self::onlyKeys($data['programs'], ['programs']);
            $programs = [];
            foreach (self::list($data['programs']['programs'] ?? []) as $row) {
                if (isset($programs[$row['code']])) { throw new RuntimeException('Duplicate source program.'); }
                $programs[$row['code']] = $row['max_year_level'];
            }
            $ids = []; $userIds = []; $numbers = [];
            foreach (self::list($data['students']['students'] ?? []) as $row) {
                if (isset($ids[$row['id']]) || isset($userIds[$row['user_id']]) || isset($numbers[$row['student_number']])
                    || !isset($programs[$row['program_code']]) || $row['year_level'] > $programs[$row['program_code']]
                    || !self::validTime($row['created_at']) || !self::validTime($row['updated_at'])) {
                    throw new RuntimeException('Invalid source student.');
                }
                $ids[$row['id']] = $row; $userIds[$row['user_id']] = true; $numbers[$row['student_number']] = true;
            }
            foreach ($data['students']['history'] ?? [] as $id => $entries) {
                if (!isset($ids[(int)$id])) { throw new RuntimeException('Orphan source student history.'); }
                $state = [];
                foreach (self::list($entries) as $entry) {
                    $field = $entry['field'];
                    if (($state[$field] ?? null) !== $entry['from']) { throw new RuntimeException('Source student replay failed.'); }
                    $state[$field] = $entry['to'];
                }
                foreach (['program_code','year_level','academic_status'] as $field) {
                    if (($state[$field] ?? null) !== $ids[(int)$id][$field]) { throw new RuntimeException('Source student replay failed.'); }
                }
            }
            foreach ($ids as $id => $_row) {
                if (!isset($data['students']['history'][(string)$id])) { throw new RuntimeException('Source student history missing.'); }
            }
        } else {
            self::onlyKeys($data, ['terms','sections','assignments','assignment_history']);
            foreach (self::list($data['terms'] ?? []) as $row) {
                if (!self::validTime($row['created_at']) || !self::validTime($row['updated_at'])) { throw new RuntimeException('Invalid source term.'); }
            }
            foreach (self::list($data['sections'] ?? []) as $row) {
                if (!self::validTime($row['created_at']) || !self::validTime($row['updated_at'])) { throw new RuntimeException('Invalid source section.'); }
            }
            // The mock snapshot validates section keys, assignments, replay and history order.
        }
    }

    private function insertAuth(array $raw): array
    {
        $users = $raw['users'] ?? []; $roles = 0; $audit = $raw['audit'] ?? [];
        foreach ($users as $row) {
            $this->statement('INSERT INTO users (id,email,status,password_hash,auth_version) VALUES (?,?,?,?,?)',
                [$row['id'],$row['email'],$row['status'],$row['password_hash'],$row['auth_version']]);
            $sorted = $row['roles']; sort($sorted, SORT_STRING);
            foreach ($sorted as $role) {
                $this->statement('INSERT INTO user_roles (user_id,role) VALUES (?,?)', [$row['id'],$role]); $roles++;
            }
        }
        foreach ($audit as $entry) {
            $this->statement('INSERT INTO audit_logs (id,at,actor_id,target_id,action,outcome,changes) VALUES (?,?,?,?,?,?,?)',
                [$entry['id'],self::sqlTime($entry['at']),$entry['actor_id'],$entry['target_id'],$entry['action'],
                    $entry['outcome'],json_encode($entry['changes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        }
        $this->setSeq('auth', $audit === [] ? 0 : max(array_column($audit, 'id')));
        return ['users'=>count($users),'user_roles'=>$roles,'audit_logs'=>count($audit)];
    }

    private function insertStudents(array $raw): array
    {
        $programs = $raw['programs']['programs'] ?? [];
        $students = $raw['students']['students'] ?? [];
        $history = $raw['students']['history'] ?? []; $historyCount = 0;
        foreach ($programs as $row) {
            $this->statement('INSERT INTO programs (code,name,max_year_level) VALUES (?,?,?)',
                [$row['code'],$row['name'],$row['max_year_level']]);
        }
        foreach ($students as $row) {
            $this->statement('INSERT INTO students (id,user_id,student_number,first_name,middle_name,last_name,program_code,year_level,academic_status,admission_year,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [$row['id'],$row['user_id'],$row['student_number'],$row['first_name'],$row['middle_name'],$row['last_name'],
                    $row['program_code'],$row['year_level'],$row['academic_status'],$row['admission_year'],
                    self::sqlTime($row['created_at']),self::sqlTime($row['updated_at'])]);
        }
        foreach ($history as $id => $entries) foreach ($entries as $entry) {
            $this->statement('INSERT INTO student_academic_history (student_id,seq,at,field,from_value,to_value,actor_id) VALUES (?,?,?,?,?,?,?)',
                [(int)$id,$entry['seq'],self::sqlTime($entry['at']),$entry['field'],
                    $entry['from'] === null ? null : (string)$entry['from'],(string)$entry['to'],$entry['actor_id']]);
            $historyCount++;
        }
        return ['programs'=>count($programs),'students'=>count($students),'student_academic_history'=>$historyCount];
    }

    private function insertEnrollment(array $raw): array
    {
        $terms = $raw['terms'] ?? []; $sections = $raw['sections'] ?? [];
        $history = $raw['assignment_history'] ?? []; $assignments = $raw['assignments'] ?? []; $meetingCount = 0;
        foreach ($terms as $row) {
            $this->statement('INSERT INTO terms (id,code,name,start_date,end_date,is_current,enrollment_open,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
                [$row['id'],$row['code'],$row['name'],$row['start_date'],$row['end_date'],(int)$row['is_current'],
                    (int)$row['enrollment_open'],self::sqlTime($row['created_at']),self::sqlTime($row['updated_at'])]);
        }
        foreach ($sections as $row) {
            $this->statement('INSERT INTO sections (id,term_id,course_code,course_title,units,section_code,capacity,approved_count,delivery_mode,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$row['id'],$row['term_id'],$row['course_code'],$row['course_title'],$row['units'],$row['section_code'],
                    $row['capacity'],$row['approved_count'],$row['delivery_mode'],self::sqlTime($row['created_at']),
                    self::sqlTime($row['updated_at'])]);
            foreach ($row['meetings'] as $position => $meeting) {
                $this->statement('INSERT INTO section_meetings (section_id,position,day,start_time,end_time,room) VALUES (?,?,?,?,?,?)',
                    [$row['id'],$position,$meeting['day'],$meeting['start'],$meeting['end'],$meeting['room']]);
                $meetingCount++;
            }
        }
        $latest = [];
        foreach ($history as $entry) {
            $this->statement('INSERT INTO section_instructor_history (seq,at,action,section_id,faculty_id,actor_id) VALUES (?,?,?,?,?,?)',
                [$entry['seq'],self::sqlTime($entry['at']),$entry['action'],$entry['section_id'],$entry['faculty_id'],$entry['actor_id']]);
            $key = $entry['section_id'] . ':' . $entry['faculty_id'];
            if ($entry['action'] === 'assigned') { $latest[$key] = $entry['seq']; }
            else { unset($latest[$key]); }
        }
        foreach ($assignments as $entry) {
            $key = $entry['section_id'] . ':' . $entry['faculty_id'];
            if (!isset($latest[$key])) { throw new RuntimeException('Assignment replay failed.'); }
            $this->statement('INSERT INTO section_instructors (section_id,faculty_id,assigned_seq) VALUES (?,?,?)',
                [$entry['section_id'],$entry['faculty_id'],$latest[$key]]);
        }
        $this->setSeq('enrollment', $history === [] ? 0 : max(array_column($history, 'seq')));
        return ['terms'=>count($terms),'sections'=>count($sections),'section_meetings'=>$meetingCount,
            'section_instructor_history'=>count($history),'section_instructors'=>count($assignments)];
    }

    private function assertEquivalent(string $module, string $runtime, array $raw): void
    {
        if ($module === 'auth') {
            $passwords = $this->pdo->query('SELECT id, password_hash FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $passwords = array_map(static fn (array $row): array =>
                ['id'=>(int)$row['id'], 'password_hash'=>$row['password_hash']], $passwords);
            $expectedPasswords = array_map(static fn (array $row): array =>
                ['id'=>$row['id'], 'password_hash'=>$row['password_hash']], $raw['users'] ?? []);
            usort($expectedPasswords, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
            self::same($expectedPasswords, $passwords);
            $mock = new MockUserRepository(new JsonStore($runtime . '/auth/users.json'));
            $sql = new MySQLUserRepository($this->pdo);
            foreach ($raw['users'] ?? [] as $row) {
                $expected = $mock->findById($row['id']); $actual = $sql->findById($row['id']);
                self::sameUser($expected, $actual);
                self::sameUser($mock->findByEmail($row['email']), $sql->findByEmail($row['email']));
            }
            self::same($mock->auditEntries(), $sql->auditEntries());
        } elseif ($module === 'students') {
            $mock = new MockStudentRepository(new JsonStore($runtime . '/students/students.json'));
            $sql = new MySQLStudentRepository($this->pdo);
            foreach ($raw['students']['students'] ?? [] as $row) {
                self::same($mock->findById($row['id'])?->toArray(), $sql->findById($row['id'])?->toArray());
                self::same($mock->findByUserId($row['user_id'])?->toArray(), $sql->findByUserId($row['user_id'])?->toArray());
                $count = count($raw['students']['history'][(string)$row['id']] ?? []);
                self::same($mock->history($row['id'], 1, max(1,$count)), $sql->history($row['id'], 1, max(1,$count)));
            }
            $mockPrograms = (new JsonProgramCatalog(new JsonStore($runtime . '/students/programs.json')))->all();
            $sqlPrograms = (new MySQLProgramCatalog($this->pdo))->all();
            $format = static function (array $items): array {
                $rows = array_map(static fn($program): array => $program->toArray(), $items);
                usort($rows, static fn(array $a,array $b): int => strcmp($a['code'],$b['code'])); return $rows;
            };
            self::same($format($mockPrograms), $format($sqlPrograms));
        } else {
            $mock = new MockCatalogRepository(new JsonStore($runtime . '/enrollment/enrollment.json'));
            $sql = new MySQLCatalogRepository($this->pdo);
            self::same(array_map(static fn($term):array=>$term->toArray(),$mock->terms()),
                array_map(static fn($term):array=>$term->toArray(),$sql->terms()));
            $ids = array_column($raw['sections'] ?? [], 'id');
            foreach ($ids as $id) { self::same($mock->findSection($id)?->toArray(),$sql->findSection($id)?->toArray()); }
            self::same($mock->assignedFacultyIds($ids),$sql->assignedFacultyIds($ids));
            $facultyIds = array_unique(array_merge(array_column($raw['assignments'] ?? [],'faculty_id'),
                array_column($raw['assignment_history'] ?? [],'faculty_id')));
            foreach ($facultyIds as $id) { self::same($mock->sectionIdsForFaculty($id),$sql->sectionIdsForFaculty($id)); }
        }
    }

    private static function sameUser($a, $b): void
    {
        if ($a === null || $b === null) { self::same($a,$b); return; }
        $ar = $a->toPublicArray(); $br = $b->toPublicArray();
        sort($ar['roles'],SORT_STRING); sort($br['roles'],SORT_STRING);
        self::same($ar,$br); self::same($a->authVersion,$b->authVersion);
    }
    private static function same(mixed $a,mixed $b): void
    { if ($a !== $b) { throw new RuntimeException('Import interface mismatch.'); } }
    private function setSeq(string $module,int $seq): void
    { $this->statement('UPDATE repository_locks SET last_seq = ? WHERE name = ?',[$seq,$module]); }
    private function statement(string $sql,array $params): \PDOStatement
    { $statement=$this->pdo->prepare($sql); $statement->execute($params); return $statement; }
    private static function sqlTime(string $value): string
    { if (!self::validTime($value)) { throw new RuntimeException('Invalid source timestamp.'); }
        return substr($value,0,10).' '.substr($value,11,8); }
    private static function validTime(mixed $value): bool
    { if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value)) { return false; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value; }
    private static function onlyKeys(array $data,array $allowed): void
    { if (array_diff(array_keys($data),$allowed) !== []) { throw new RuntimeException('Invalid source keys.'); } }
    private static function list(mixed $value): array
    { if (!is_array($value) || !array_is_list($value)) { throw new RuntimeException('Invalid source list.'); } return $value; }
    private static function hasSecret(array $values): bool
    { foreach ($values as $key=>$value) {
        if (is_string($key) && preg_match('/pass|hash|token|secret|csrf|session|cookie/i',$key)) { return true; }
        if (is_array($value) && self::hasSecret($value)) { return true; }
      } return false; }
}
