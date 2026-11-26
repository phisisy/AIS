<?php
declare(strict_types=1);

// DB-18 faculty import tests. UNVERIFIED: written source-only, never executed. Online only (AIS_MYSQL_TESTS=1, ais_test).
// The phase8* helpers live in ImportMySQLTest.php / ImportOfflineTest.php, which load after this file, so they are used at run time only.

use App\Core\JsonStore;
use App\Faculty\Repositories\MockFacultyRepository;
use App\Faculty\Repositories\MySQLFacultyRepository;

require_once __DIR__ . '/MySQLTestSupport.php';

function dbfOnline(): bool
{
    return getenv('AIS_MYSQL_TESTS') === '1' && is_string(getenv('AIS_MYSQL_TEST_APP_PASSWORD')) && MySQLTestSupport::enabled();
}

/** The phase 8 fixture plus two faculty profiles (users 2 and 4 = faculty ids 1 and 2, as the section assignments expect) and 7 history rows. */
function dbfRuntime(): string
{
    $runtime = phase8Runtime();
    $repo = new MockFacultyRepository(new JsonStore($runtime . '/faculty/faculty.json'));
    $now = '2025-01-01T03:04:05Z';
    foreach ([[2, 'DBF-001'], [4, 'DBF-002']] as [$userId, $number]) {
        $repo->create(['user_id' => $userId, 'employee_number' => $number, 'first_name' => 'Faculty',
            'last_name' => 'Fixture' . $userId, 'department' => 'Science', 'status' => 'active'], $now, 1);
    }
    $repo->update(1, ['department' => 'Mathematics'], '2025-01-02T03:04:05Z', 1);
    $repo->update(2, ['status' => 'inactive'], '2025-01-02T03:04:06Z', 1);
    $repo->update(2, ['status' => 'active'], '2025-01-02T03:04:07Z', 1);
    $repo->update(1, ['first_name' => 'Renamed'], '2025-01-02T03:04:08Z', 1); // untracked field: no history row
    return $runtime;
}

function dbfCount(PDO $owner, string $table): int
{
    return (int) $owner->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}

function dbfFacultyLock(PDO $owner): int
{
    return (int) $owner->query("SELECT last_seq FROM repository_locks WHERE name = 'faculty'")->fetchColumn();
}

function dbfFacultyHash(PDO $owner): string
{
    return hash('sha256', serialize([$owner->query('SELECT * FROM faculty ORDER BY id')->fetchAll(),
        $owner->query('SELECT * FROM faculty_history ORDER BY seq')->fetchAll(), dbfFacultyLock($owner)]));
}

function dbfAssertUntouched(PDO $owner): void
{
    same([0, 0, 0], [dbfCount($owner, 'faculty'), dbfCount($owner, 'faculty_history'), dbfFacultyLock($owner)]);
}

if (dbfOnline()) {
    test('DB-18 MySQL: faculty import round trip preserves ids and history, sets the lock, matches the mock and verifies', static function (): void {
        $owner = phase8FreshDatabase();
        $runtime = dbfRuntime();
        try {
            $raw = (new JsonStore($runtime . '/faculty/faculty.json'))->read();
            same([2, 7], [count($raw['faculty']), count($raw['history'])]);
            $source = phase8SourceHashes($runtime);
            [$exit, $stdout, $stderr] = phase8Import($runtime, 'faculty');
            same(2, $exit); same("Auth dependency missing.\n", $stderr);   // faculty requires auth first
            dbfAssertUntouched($owner);
            same(0, phase8Import($runtime, 'auth')[0]);
            [$exit, $stdout, $stderr] = phase8Import($runtime, 'faculty');
            same(0, $exit);
            check(str_contains($stdout, 'faculty 2') && str_contains($stdout, 'faculty_history 7') && str_contains($stdout, 'IMPORT COMMITTED'), $stderr . $stdout);
            same($source, phase8SourceHashes($runtime));
            same(array_column($raw['faculty'], 'id'), array_map('intval', $owner->query('SELECT id FROM faculty ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
            same(range(1, 7), array_map('intval', $owner->query('SELECT seq FROM faculty_history ORDER BY seq')->fetchAll(PDO::FETCH_COLUMN)));
            same(7, dbfFacultyLock($owner));
            $mock = new MockFacultyRepository(new JsonStore($runtime . '/faculty/faculty.json'));
            $sql = new MySQLFacultyRepository($owner);
            foreach ($raw['faculty'] as $row) {
                same($mock->findById($row['id'])->toArray(), $sql->findById($row['id'])->toArray());
                same($row, $sql->findByUserId($row['user_id'])->toArray());
            }
            $history = array_map(static fn (array $r): array => ['seq' => (int) $r['seq'], 'at' => str_replace(' ', 'T', $r['at']) . 'Z',
                'faculty_id' => (int) $r['faculty_id'], 'field' => $r['field'], 'from' => $r['from_value'], 'to' => $r['to_value'],
                'actor_id' => (int) $r['actor_id']],
                $owner->query('SELECT * FROM faculty_history ORDER BY seq')->fetchAll(PDO::FETCH_ASSOC));
            same($raw['history'], $history);
            // The next app write continues the imported sequence without a gap.
            $sql->update(2, ['department' => 'Physics'], '2025-01-03T00:00:00Z', 1);
            same([8, 8], [dbfFacultyLock($owner), (int) $owner->query('SELECT MAX(seq) FROM faculty_history')->fetchColumn()]);
            // The verifier stays clean after the app write.
            [$exit, $stdout] = phase8Verify($runtime);
            same(0, $exit);
            check(str_contains($stdout, 'PASS') && !str_contains($stdout, 'FAIL'), $stdout);
        } finally {
            removeTestDirectory($runtime);
        }
    });

    test('DB-18 MySQL: a repeat import and a non-empty target are refused and change nothing; dry run writes nothing', static function (): void {
        $owner = phase8FreshDatabase();
        $runtime = dbfRuntime();
        try {
            same(0, phase8Import($runtime, 'auth')[0]);
            [$exit, $stdout] = phase8Import($runtime, 'faculty', ['--dry-run']);
            same(0, $exit); check(str_contains($stdout, 'DRY RUN ROLLED BACK'));
            dbfAssertUntouched($owner);
            same(0, phase8Import($runtime, 'faculty')[0]);
            $before = dbfFacultyHash($owner);
            $source = phase8SourceHashes($runtime);
            [$exit, $stdout, $stderr] = phase8Import($runtime, 'faculty');
            same(2, $exit); same('', $stdout); same("Module already imported.\n", $stderr);
            same($before, dbfFacultyHash($owner));
            same($source, phase8SourceHashes($runtime));
        } finally {
            removeTestDirectory($runtime);
        }
        // Unused lock but a row already present: the emptiness check refuses.
        $owner = phase8FreshDatabase();
        $runtime = dbfRuntime();
        try {
            same(0, phase8Import($runtime, 'auth')[0]);
            phase8SeedFaculty($owner, 1);
            $before = dbfFacultyHash($owner);
            [$exit, $stdout, $stderr] = phase8Import($runtime, 'faculty');
            same(2, $exit); same('', $stdout); same("Module target is not empty.\n", $stderr);
            same($before, dbfFacultyHash($owner));
        } finally {
            removeTestDirectory($runtime);
        }
    });

    test('DB-18 MySQL: an equivalence failure after the inserts rolls the whole faculty module back', static function (): void {
        $owner = phase8FreshDatabase();
        $runtime = dbfRuntime();
        try {
            same(0, phase8Import($runtime, 'auth')[0]);
            $owner->exec('CREATE TRIGGER ais_test.dbf_history_change BEFORE INSERT ON ais_test.faculty_history '
                . 'FOR EACH ROW SET NEW.at = NEW.at + INTERVAL 1 SECOND');
            $source = phase8SourceHashes($runtime);
            same(1, phase8Import($runtime, 'faculty')[0]);
            dbfAssertUntouched($owner);
            same($source, phase8SourceHashes($runtime));
        } finally {
            $owner->exec('DROP TRIGGER IF EXISTS ais_test.dbf_history_change');
            same(0, (int) $owner->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = 'ais_test' AND TRIGGER_NAME = 'dbf_history_change'")->fetchColumn());
            removeTestDirectory($runtime);
        }
    });

    test('DB-18 MySQL: an orphan user (profile or history actor) is refused and rolls back', static function (): void {
        foreach (['profile user' => static function (array &$d): void { $d['faculty'][1]['user_id'] = 999; },
            'history actor' => static function (array &$d): void { $d['history'][3]['actor_id'] = 999; }] as $name => $corrupt) {
            $owner = phase8FreshDatabase();
            $runtime = dbfRuntime();
            try {
                same(0, phase8Import($runtime, 'auth')[0]);
                (new JsonStore($runtime . '/faculty/faculty.json'))->transaction($corrupt);
                $source = phase8SourceHashes($runtime);
                check(phase8Import($runtime, 'faculty')[0] === 1, $name);
                dbfAssertUntouched($owner);
                same($source, phase8SourceHashes($runtime));
            } finally {
                removeTestDirectory($runtime);
            }
        }
    });

    test('DB-18 MySQL: a corrupt faculty source (history gap, replay break, bad time, duplicate, extra key) aborts before any write', static function (): void {
        foreach (['history seq gap' => static function (array &$d): void { $d['history'][2]['seq'] = 9; },
            'replay break' => static function (array &$d): void { $d['history'][4]['from'] = 'Nowhere'; },
            'final state differs from history' => static function (array &$d): void { $d['faculty'][0]['status'] = 'inactive'; },
            'bad timestamp' => static function (array &$d): void { $d['history'][0]['at'] = '2025-02-30T00:00:00Z'; },
            'duplicate employee number' => static function (array &$d): void { $d['faculty'][1]['employee_number'] = $d['faculty'][0]['employee_number']; },
            'duplicate user' => static function (array &$d): void { $d['faculty'][1]['user_id'] = $d['faculty'][0]['user_id']; },
            'history for unknown profile' => static function (array &$d): void { $d['history'][0]['faculty_id'] = 77; },
            'unexpected key' => static function (array &$d): void { $d['notes'] = []; }] as $name => $corrupt) {
            $owner = phase8FreshDatabase();
            $runtime = dbfRuntime();
            try {
                same(0, phase8Import($runtime, 'auth')[0]);
                (new JsonStore($runtime . '/faculty/faculty.json'))->transaction($corrupt);
                $source = phase8SourceHashes($runtime);
                check(phase8Import($runtime, 'faculty')[0] === 1, $name);
                dbfAssertUntouched($owner);
                same($source, phase8SourceHashes($runtime));
            } finally {
                removeTestDirectory($runtime);
            }
        }
    });

    test('DB-18 MySQL: enrollment import needs faculty first when sections have instructor assignments', static function (): void {
        $owner = phase8FreshDatabase();
        $runtime = dbfRuntime();
        try {
            same(0, phase8Import($runtime, 'auth')[0]);
            same(0, phase8Import($runtime, 'students')[0]);
            $raw = (new JsonStore($runtime . '/enrollment/enrollment.json'))->read();
            check($raw['assignments'] !== [], 'Fixture must contain instructor assignments.');
            $source = phase8SourceHashes($runtime);
            [$exit, $stdout, $stderr] = phase8Import($runtime, 'enrollment');
            same(2, $exit); same('', $stdout); same("Faculty dependency missing.\n", $stderr);
            foreach (['terms', 'sections', 'section_meetings', 'section_instructors', 'section_instructor_history'] as $table) {
                same(0, dbfCount($owner, $table));
            }
            same(0, (int) $owner->query("SELECT last_seq FROM repository_locks WHERE name = 'enrollment'")->fetchColumn());
            same($source, phase8SourceHashes($runtime));
            // With faculty imported through the module, the same enrollment source imports and verifies.
            same(0, phase8Import($runtime, 'faculty')[0]);
            [$exit, , $stderr] = phase8Import($runtime, 'enrollment');
            check($exit === 0, $stderr);
            same(count($raw['assignments']), dbfCount($owner, 'section_instructors'));
            same(count($raw['assignment_history']), dbfCount($owner, 'section_instructor_history'));
            [$exit, $stdout] = phase8Verify($runtime);
            same(0, $exit);
            check(!str_contains($stdout, 'FAIL'), $stdout);
        } finally {
            removeTestDirectory($runtime);
        }
    });
}
