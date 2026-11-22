<?php
declare(strict_types=1);

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

require_once __DIR__ . '/MySQLTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/tools/MigrationRunner.php';

function phase8OnlineEnabled(): bool
{
    return getenv('AIS_MYSQL_TESTS') === '1'
        && is_string(getenv('AIS_MYSQL_TEST_APP_PASSWORD'))
        && MySQLTestSupport::enabled();
}

/** All fixtures are invented through the current mock write paths in a private temp directory. */
function phase8Runtime(): string
{
    $runtime = tempDirectory();
    $now = '2025-01-01T03:04:05Z';
    $users = new MockUserRepository(new JsonStore($runtime . '/auth/users.json'));
    $hash = password_hash('Disposable Phase 8 fixture', PASSWORD_DEFAULT);
    $users->seed([
        ['id' => 1, 'email' => 'admin@example.test', 'roles' => ['registrar', 'admin'], 'status' => 'active',
            'password_hash' => $hash, 'auth_version' => 1],
        ['id' => 2, 'email' => 'faculty1@example.test', 'roles' => ['faculty'], 'status' => 'active',
            'password_hash' => $hash, 'auth_version' => 1],
        ['id' => 3, 'email' => 'student1@example.test', 'roles' => ['student'], 'status' => 'active',
            'password_hash' => $hash, 'auth_version' => 1],
        ['id' => 4, 'email' => 'student2@example.test', 'roles' => ['student', 'faculty'], 'status' => 'active',
            'password_hash' => $hash, 'auth_version' => 1],
    ]);
    $users->transaction(static function (UserTransactionInterface $tx): void {
        $tx->appendAudit(1, 2, 'user.updated', ['roles' => ['from' => ['student'], 'to' => ['faculty']]],
            '2025-01-01T03:04:05Z');
        $tx->appendAudit(1, 0, 'user.created', ['reason' => 'fixture'], '2025-01-01T03:04:06Z', 'denied');
    });
    (new JsonProgramCatalog(new JsonStore($runtime . '/students/programs.json')))->seed([
        ['code' => 'BSCS', 'name' => 'Computer Science', 'max_year_level' => 4],
        ['code' => 'BSIT', 'name' => 'Information Technology', 'max_year_level' => 4],
    ]);
    $students = new MockStudentRepository(new JsonStore($runtime . '/students/students.json'));
    foreach ([3, 4] as $userId) {
        $students->create(['user_id' => $userId, 'student_number' => 'P8-000' . $userId,
            'first_name' => 'Student', 'middle_name' => null, 'last_name' => 'Fixture',
            'program_code' => 'BSCS', 'year_level' => 1, 'academic_status' => 'active',
            'admission_year' => 2025], $now, 1);
    }
    $students->update(1, ['program_code' => 'BSIT', 'year_level' => 2], '2025-01-02T03:04:05Z', null, 1);
    $catalog = new MockCatalogRepository(new JsonStore($runtime . '/enrollment/enrollment.json'));
    $term = $catalog->createTerm(['code' => '2025-A', 'name' => 'First term', 'start_date' => '2025-01-01',
        'end_date' => '2025-05-01', 'is_current' => true, 'enrollment_open' => true], $now);
    $catalog->createTerm(['code' => '2025-B', 'name' => 'Second term', 'start_date' => '2025-08-01',
        'end_date' => '2025-12-01', 'is_current' => false, 'enrollment_open' => false], $now);
    $scheduled = $catalog->createSection(['term_id' => $term->id(), 'course_code' => 'CS101',
        'course_title' => 'Computing', 'units' => 3, 'section_code' => 'A', 'capacity' => 30,
        'delivery_mode' => 'scheduled', 'meetings' => [
            ['day' => 'MON', 'start' => '08:00', 'end' => '09:00', 'room' => 'R101'],
            ['day' => 'THU', 'start' => '08:00', 'end' => '09:00', 'room' => 'R101'],
        ]], $now);
    $tba = $catalog->createSection(['term_id' => $term->id(), 'course_code' => 'CS102',
        'course_title' => 'Seminar', 'units' => 2, 'section_code' => 'A', 'capacity' => 20,
        'delivery_mode' => 'tba', 'meetings' => []], $now);
    $catalog->createSection(['term_id' => $term->id(), 'course_code' => 'CS103',
        'course_title' => 'Independent work', 'units' => 2, 'section_code' => 'A', 'capacity' => 20,
        'delivery_mode' => 'online_async', 'meetings' => [
            ['day' => 'FRI', 'start' => '10:00', 'end' => '11:00', 'room' => 'R102'],
        ]], $now);
    $catalog->assignInstructor($scheduled->id(), 1, $now, 1);
    $catalog->assignInstructor($scheduled->id(), 2, $now, 1);
    $catalog->unassignInstructor($scheduled->id(), 1, $now, 1);
    $catalog->assignInstructor($scheduled->id(), 1, $now, 1);
    $catalog->assignInstructor($tba->id(), 1, $now, 1);
    return $runtime;
}

function phase8SourceHashes(string $runtime): array
{
    $hashes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isFile()) {
            $hashes[substr($file->getPathname(), strlen($runtime) + 1)] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($hashes);
    return $hashes;
}

function phase8FreshDatabase(): PDO
{
    $owner = MySQLTestSupport::connect();
    check(str_starts_with((string) $owner->query('SELECT CURRENT_USER()')->fetchColumn(), 'ais_tester@'));
    MySQLTestSupport::reset($owner);
    (new \Database\Tools\MigrationRunner($owner, 'ais_test', dirname(__DIR__, 2) . '/database/migrations'))->up();
    return $owner;
}

function phase8OnlineEnvironment(string $runtime, bool $app = false, array $overrides = []): array
{
    return phase8CliEnvironment($runtime, array_replace([
        'DB_HOST' => (string) getenv('DB_HOST'), 'DB_PORT' => (string) getenv('DB_PORT'),
        'DB_DATABASE' => (string) getenv('DB_DATABASE'),
        'DB_USERNAME' => $app ? 'ais_test_app' : (string) getenv('DB_USERNAME'),
        'DB_PASSWORD' => $app ? (string) getenv('AIS_MYSQL_TEST_APP_PASSWORD') : (string) getenv('DB_PASSWORD'),
        'DB_MIGRATION_USERNAME' => (string) getenv('DB_USERNAME'),
        'DB_MIGRATION_PASSWORD' => (string) getenv('DB_PASSWORD'),
    ], $overrides));
}

function phase8Import(string $runtime, string $module, array $extra = []): array
{
    return runProcess(phase8ImportCommand($runtime, $module, 'ais_test', $extra), phase8OnlineEnvironment($runtime));
}

function phase8Verify(string $runtime, bool $app = true, array $overrides = []): array
{
    return runProcess(phase8VerifyCommand(), phase8OnlineEnvironment($runtime, $app, $overrides));
}

function phase8SeedFaculty(PDO $owner, int $count = 2): void
{
    $statement = $owner->prepare('INSERT INTO faculty (id, user_id, employee_number, first_name, last_name, '
        . 'department, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    for ($id = 1; $id <= $count; $id++) {
        $statement->execute([$id, $id === 1 ? 2 : 4, 'P8-' . $id, 'Faculty', 'Fixture', 'Science',
            'active', '2025-01-01 03:04:05', '2025-01-01 03:04:05']);
    }
}

function phase8ImportAll(PDO $owner, string $runtime): void
{
    foreach (['auth', 'students', 'enrollment'] as $module) {
        if ($module === 'students') {
            phase8SeedFaculty($owner);
        }
        [$exit, $stdout, $stderr] = phase8Import($runtime, $module);
        check($exit === 0, 'Import ' . $module . ' failed: ' . $stderr);
        check($stdout !== '', 'Import did not report counts.');
    }
}

if (phase8OnlineEnabled()) {
    test('Phase 8 E1 E2 E3 E9 E10 E11 V1 V3: full import preserves data and verifies read-only', static function (): void {
        $owner = phase8FreshDatabase();
        $runtime = phase8Runtime();
        try {
            $source = phase8SourceHashes($runtime);
            phase8ImportAll($owner, $runtime);
            same($source, phase8SourceHashes($runtime));
            $rawUsers = (new JsonStore($runtime . '/auth/users.json'))->read();
            $rawPrograms = (new JsonStore($runtime . '/students/programs.json'))->read();
            $rawStudents = (new JsonStore($runtime . '/students/students.json'))->read();
            $rawCatalog = (new JsonStore($runtime . '/enrollment/enrollment.json'))->read();
            $counts = ['users' => count($rawUsers['users']), 'user_roles' => array_sum(array_map(
                    static fn (array $row): int => count($row['roles']), $rawUsers['users'])),
                'audit_logs' => count($rawUsers['audit']), 'programs' => count($rawPrograms['programs']),
                'students' => count($rawStudents['students']),
                'student_academic_history' => array_sum(array_map('count', $rawStudents['history'])),
                'terms' => count($rawCatalog['terms']), 'sections' => count($rawCatalog['sections']),
                'section_meetings' => array_sum(array_map(static fn (array $row): int => count($row['meetings']), $rawCatalog['sections'])),
                'section_instructors' => count($rawCatalog['assignments']),
                'section_instructor_history' => count($rawCatalog['assignment_history'])];
            foreach ($counts as $table => $expected) {
                same($expected, (int) $owner->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
            }
            foreach (['users' => $rawUsers['users'], 'students' => $rawStudents['students'],
                'terms' => $rawCatalog['terms'], 'sections' => $rawCatalog['sections']] as $table => $rows) {
                same(array_column($rows, 'id'), array_map('intval', $owner->query('SELECT id FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
            }
            same([2, 1], array_map('intval', $owner->query('SELECT faculty_id FROM section_instructors WHERE section_id = 1 ORDER BY assigned_seq')->fetchAll(PDO::FETCH_COLUMN)));
            foreach (['auth' => 'audit_logs', 'students' => 'student_academic_history',
                'enrollment' => 'section_instructor_history'] as $lock => $table) {
                $stream = 'MAX(' . ($table === 'audit_logs' ? 'id' : 'seq') . ')';
                $maximum = $lock === 'students' ? 0
                    : (int) $owner->query('SELECT COALESCE(' . $stream . ', 0) FROM ' . $table)->fetchColumn();
                $row = $owner->prepare('SELECT last_seq FROM repository_locks WHERE name = ?');
                $row->execute([$lock]);
                same($maximum, (int) $row->fetchColumn());
            }
            same($rawUsers['audit'], (new MySQLUserRepository($owner))->auditEntries());
            same(array_map(static fn ($program): array => $program->toArray(),
                (new JsonProgramCatalog(new JsonStore($runtime . '/students/programs.json')))->all()),
                array_map(static fn ($program): array => $program->toArray(),
                    (new MySQLProgramCatalog($owner))->all()));
            $mockUsers = new MockUserRepository(new JsonStore($runtime . '/auth/users.json'));
            $sqlUsers = new MySQLUserRepository($owner);
            foreach ($rawUsers['users'] as $row) {
                $expected = $mockUsers->findByEmail($row['email']);
                $actual = $sqlUsers->findById($row['id']);
                same($expected->id, $actual->id);
                same($expected->email, $actual->email);
                same($expected->status, $actual->status);
                same($expected->authVersion, $actual->authVersion);
                $expectedRoles = $expected->roles;
                $actualRoles = $actual->roles;
                sort($expectedRoles);
                sort($actualRoles);
                same($expectedRoles, $actualRoles);
                same($actual->id, $sqlUsers->findByEmail($row['email'])->id);
            }
            $mockStudents = new MockStudentRepository(new JsonStore($runtime . '/students/students.json'));
            $sqlStudents = new MySQLStudentRepository($owner);
            foreach ($rawStudents['students'] as $row) {
                same($mockStudents->findById($row['id'])->toArray(), $sqlStudents->findById($row['id'])->toArray());
                same($mockStudents->history($row['id'], 1, 100), $sqlStudents->history($row['id'], 1, 100));
            }
            $mockCatalog = new MockCatalogRepository(new JsonStore($runtime . '/enrollment/enrollment.json'));
            $sqlCatalog = new MySQLCatalogRepository($owner);
            foreach ($rawCatalog['sections'] as $row) {
                same($mockCatalog->findSection($row['id'])->toArray(), $sqlCatalog->findSection($row['id'])->toArray());
            }
            same($mockCatalog->assignedFacultyIds([1, 2, 3]), $sqlCatalog->assignedFacultyIds([1, 2, 3]));
            $actualAssignmentHistory = $owner->query('SELECT seq, at, action, section_id, faculty_id, actor_id '
                . 'FROM section_instructor_history ORDER BY seq')->fetchAll();
            $actualAssignmentHistory = array_map(static fn (array $row): array => [
                'seq' => (int) $row['seq'], 'at' => str_replace(' ', 'T', $row['at']) . 'Z',
                'action' => $row['action'], 'section_id' => (int) $row['section_id'],
                'faculty_id' => (int) $row['faculty_id'], 'actor_id' => (int) $row['actor_id'],
            ], $actualAssignmentHistory);
            same($rawCatalog['assignment_history'], $actualAssignmentHistory);
            $before = [];
            foreach (array_merge(array_keys($counts), ['faculty', 'faculty_history', 'repository_locks', 'schema_migrations']) as $table) {
                $before[$table] = hash('sha256', json_encode($owner->query('SELECT * FROM ' . $table)->fetchAll(), JSON_THROW_ON_ERROR));
            }
            [$exit, $stdout] = phase8Verify($runtime);
            same(0, $exit);
            check(str_contains($stdout, 'PASS'));
            foreach ($before as $table => $hash) {
                same($hash, hash('sha256', json_encode($owner->query('SELECT * FROM ' . $table)->fetchAll(), JSON_THROW_ON_ERROR)));
            }
            $sqlUsers->transaction(static function (UserTransactionInterface $tx): void {
                $tx->appendAudit(1, 2, 'user.updated', ['reason' => 'post-import'], '2025-01-03T03:04:05Z');
            });
            same(3, (int) $owner->query('SELECT MAX(id) FROM audit_logs')->fetchColumn());
            same(3, (int) $owner->query("SELECT last_seq FROM repository_locks WHERE name = 'auth'")->fetchColumn());
            // Explicit ids must advance each entity's AUTO_INCREMENT counter.
            $owner->prepare('INSERT INTO users (email, status, password_hash, auth_version) VALUES (?, ?, ?, 1)')
                ->execute(['next@example.test', 'active', password_hash('Next fixture', PASSWORD_DEFAULT)]);
            same(5, (int) $owner->lastInsertId());
            $owner->prepare('INSERT INTO students (user_id, student_number, first_name, middle_name, last_name, program_code, '
                . 'year_level, academic_status, admission_year, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([5, 'P8-0005', 'Next', null, 'Fixture', 'BSCS', 1, 'active', 2025,
                    '2025-01-01 03:04:05', '2025-01-01 03:04:05']);
            same(3, (int) $owner->lastInsertId());
            $owner->exec("INSERT INTO terms (code, name, start_date, end_date, is_current, enrollment_open, created_at, updated_at) "
                . "VALUES ('2026-A', 'Next term', '2026-01-01', '2026-05-01', 0, 0, '2025-01-01 03:04:05', '2025-01-01 03:04:05')");
            same(3, (int) $owner->lastInsertId());
            $owner->exec("INSERT INTO sections (term_id, course_code, course_title, units, section_code, capacity, "
                . "approved_count, delivery_mode, created_at, updated_at) VALUES (3, 'CS201', 'Next section', 3, 'A', "
                . "20, 0, 'tba', '2025-01-01 03:04:05', '2025-01-01 03:04:05')");
            same(4, (int) $owner->lastInsertId());
        } finally {
            removeTestDirectory($runtime);
        }
    });

    test('Phase 8 E4 E6: corrupt source aborts before any write', static function (): void {
        foreach (['duplicate_id', 'duplicate_key', 'bad_email', 'bad_role', 'bad_time', 'bad_meeting'] as $kind) {
            $owner = phase8FreshDatabase();
            $runtime = phase8Runtime();
            try {
                if (in_array($kind, ['duplicate_id', 'duplicate_key', 'bad_email', 'bad_role', 'bad_time'], true)) {
                    (new JsonStore($runtime . '/auth/users.json'))->transaction(static function (array &$data) use ($kind): void {
                        if ($kind === 'duplicate_id') { $data['users'][1]['id'] = 1; }
                        if ($kind === 'duplicate_key') { $data['users'][1]['email'] = $data['users'][0]['email']; }
                        if ($kind === 'bad_email') { $data['users'][1]['email'] = 'not-an-email'; }
                        if ($kind === 'bad_role') { $data['users'][1]['roles'] = ['unknown']; }
                        if ($kind === 'bad_time') { $data['audit'][0]['at'] = 'bad timestamp'; }
                    });
                    $module = 'auth';
                } else {
                    (new JsonStore($runtime . '/enrollment/enrollment.json'))->transaction(static function (array &$data): void {
                        $data['sections'][0]['meetings'][0]['end'] = '07:00';
                    });
                    $module = 'enrollment';
                }
                $source = phase8SourceHashes($runtime);
                [$exit] = phase8Import($runtime, $module);
                same(1, $exit);
                same(0, (int) $owner->query('SELECT COUNT(*) FROM ' . ($module === 'auth' ? 'users' : 'terms'))->fetchColumn());
                same($source, phase8SourceHashes($runtime));
            } finally {
                removeTestDirectory($runtime);
            }
        }
    });

    test('Phase 8 E5 E7: SQL foreign-key failures roll back the whole module', static function (): void {
        $owner = phase8FreshDatabase();
        $runtime = phase8Runtime();
        try {
            same(0, phase8Import($runtime, 'auth')[0]);
            (new JsonStore($runtime . '/students/students.json'))->transaction(static function (array &$data): void {
                $data['students'][1]['user_id'] = 999;
            });
            $source = phase8SourceHashes($runtime);
            same(1, phase8Import($runtime, 'students')[0]);
            foreach (['programs', 'students', 'student_academic_history'] as $table) {
                same(0, (int) $owner->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
            }
            same(0, (int) $owner->query("SELECT last_seq FROM repository_locks WHERE name = 'students'")->fetchColumn());
            same($source, phase8SourceHashes($runtime));
        } finally {
            removeTestDirectory($runtime);
        }
        $owner = phase8FreshDatabase();
        $runtime = phase8Runtime();
        try {
            same(0, phase8Import($runtime, 'auth')[0]);
            phase8SeedFaculty($owner, 1);
            $source = phase8SourceHashes($runtime);
            same(1, phase8Import($runtime, 'enrollment')[0]);
            foreach (['terms', 'sections', 'section_meetings', 'section_instructors', 'section_instructor_history'] as $table) {
                same(0, (int) $owner->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
            }
            same(0, (int) $owner->query("SELECT last_seq FROM repository_locks WHERE name = 'enrollment'")->fetchColumn());
            same($source, phase8SourceHashes($runtime));
        } finally {
            removeTestDirectory($runtime);
        }
    });

    test('Phase 8 E4 E8 E12: non-empty target refuses repeat; dry run writes nothing', static function (): void {
        $owner = phase8FreshDatabase();
        $runtime = phase8Runtime();
        try {
            $source = phase8SourceHashes($runtime);
            same(0, phase8Import($runtime, 'auth', ['--dry-run'])[0]);
            same(0, (int) $owner->query('SELECT COUNT(*) FROM users')->fetchColumn());
            same(0, (int) $owner->query("SELECT last_seq FROM repository_locks WHERE name = 'auth'")->fetchColumn());
            same(0, phase8Import($runtime, 'auth')[0]);
            $before = [];
            foreach (['users'=>'id', 'user_roles'=>'user_id, role', 'audit_logs'=>'id'] as $table => $order) {
                $before[$table] = hash('sha256', serialize($owner->query('SELECT * FROM ' . $table . ' ORDER BY ' . $order)->fetchAll()));
            }
            $before['auth_lock'] = hash('sha256', serialize($owner->query("SELECT * FROM repository_locks WHERE name = 'auth'")->fetchAll()));
            same(2, phase8Import($runtime, 'auth')[0]);
            foreach (['users'=>'id', 'user_roles'=>'user_id, role', 'audit_logs'=>'id'] as $table => $order) {
                same($before[$table], hash('sha256', serialize($owner->query('SELECT * FROM ' . $table . ' ORDER BY ' . $order)->fetchAll())));
            }
            same($before['auth_lock'], hash('sha256', serialize($owner->query("SELECT * FROM repository_locks WHERE name = 'auth'")->fetchAll())));
            same($source, phase8SourceHashes($runtime));
        } finally {
            removeTestDirectory($runtime);
        }
    });

    test('Phase 8 E8: equivalence failure after inserts rolls back auth', static function (): void {
        $owner = phase8FreshDatabase(); $runtime = phase8Runtime();
        try {
            check($owner->query('SELECT DATABASE()')->fetchColumn() === 'ais_test');
            $owner->exec("CREATE TRIGGER ais_test.phase8_audit_change BEFORE INSERT ON ais_test.audit_logs "
                . "FOR EACH ROW SET NEW.action = 'changed.by.test'");
            same(1, phase8Import($runtime, 'auth')[0]);
            foreach (['users', 'user_roles', 'audit_logs'] as $table) {
                same(0, (int)$owner->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
            }
            same(0, (int)$owner->query("SELECT last_seq FROM repository_locks WHERE name = 'auth'")->fetchColumn());
        } finally {
            check($owner->query('SELECT DATABASE()')->fetchColumn() === 'ais_test');
            $owner->exec('DROP TRIGGER IF EXISTS ais_test.phase8_audit_change');
            same(0, (int)$owner->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = 'ais_test' "
                . "AND TRIGGER_NAME = 'phase8_audit_change'")->fetchColumn());
            removeTestDirectory($runtime);
        }
    });

    test('Phase 8 refusals: dependency, schema and dirty target', static function (): void {
        $owner = phase8FreshDatabase(); $runtime = phase8Runtime();
        try {
            same(2, phase8Import($runtime, 'students')[0]);
            $version = (int)$owner->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
            $owner->prepare("UPDATE schema_migrations SET state = 'started' WHERE version = ?")->execute([$version]);
            same(2, phase8Import($runtime, 'auth')[0]);
        } finally { removeTestDirectory($runtime); }
        $owner = phase8FreshDatabase(); $runtime = phase8Runtime();
        try {
            $owner->prepare('INSERT INTO users (id,email,status,password_hash,auth_version) VALUES (?,?,?,?,?)')
                ->execute([99, 'dirty@example.test', 'active', password_hash('Fixture', PASSWORD_DEFAULT), 1]);
            same(2, phase8Import($runtime, 'auth')[0]);
        } finally { removeTestDirectory($runtime); }
    });
}
