<?php
declare(strict_types=1);

// T-60 V9 timestamp round trip for the student and faculty adapters (trusted run 2026-11-26). The catalog case is
// 'T-60 V9: zero DATETIME timestamp row …' (FailClosedMySQLTest, fixed by patch 04a). Online only; ais_test only.

use App\Faculty\Repositories\MySQLFacultyRepository;
use App\Students\Repositories\MySQLStudentRepository;
use Database\Tools\MigrationRunner;

require_once __DIR__ . '/MySQLTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/tools/MigrationRunner.php';

/** Fresh schema; user 1 (student) with student 1, user 2 (faculty) with faculty 1; all rows valid. */
function timestampRoundTripFixture(): PDO
{
    $owner = MySQLTestSupport::connect();
    MySQLTestSupport::reset($owner);
    (new MigrationRunner($owner, 'ais_test', dirname(__DIR__, 2) . '/database/migrations'))->up();
    $hash = password_hash('Timestamp round trip passphrase', PASSWORD_DEFAULT);
    $user = $owner->prepare('INSERT INTO users (id, email, status, password_hash, auth_version) VALUES (?, ?, ?, ?, 1)');
    $user->execute([1, 'ts-student@example.test', 'active', $hash]);
    $user->execute([2, 'ts-faculty@example.test', 'active', $hash]);
    $owner->exec("INSERT INTO programs (code, name, max_year_level) VALUES ('BSCS', 'Computer Science', 4)");
    $owner->exec("INSERT INTO students (id, user_id, student_number, first_name, middle_name, last_name, program_code, year_level, "
        . "academic_status, admission_year, created_at, updated_at) VALUES (1, 1, 'TS-1', 'Time', NULL, 'Stamp', 'BSCS', 1, 'active', 2025, "
        . "'2025-01-01 03:04:05', '2025-01-01 03:04:05')");
    $owner->exec("INSERT INTO faculty (id, user_id, employee_number, first_name, last_name, department, status, created_at, updated_at) "
        . "VALUES (1, 2, 'TS-001', 'Time', 'Keeper', 'Science', 'active', '2025-01-01 03:04:05', '2025-01-01 03:04:05')");
    MySQLTestSupport::completeFixture($owner, [1 => 'student', 2 => 'faculty']);
    return $owner;
}

/** Valid row reads back (non-vacuous), then a zero updated_at must fail closed with the generic, unchained message. */
function timestampRoundTrip(string $table, callable $read, string $message): void
{
    $owner = timestampRoundTripFixture();
    check($read() !== null, "Baseline {$table} row must read back.");
    $owner->exec("SET SESSION sql_mode = ''");
    same(1, $owner->exec("UPDATE {$table} SET updated_at = '0000-00-00 00:00:00' WHERE id = 1"));
    $error = throws(RuntimeException::class, $read);
    same([$message, null], [$error->getMessage(), $error->getPrevious()]);
}

if (is_string(getenv('AIS_MYSQL_TEST_APP_PASSWORD')) && MySQLTestSupport::enabled()) {
    test('T-60 V9 MySQL (student): a zero updated_at row fails closed, generic and unchained', static function (): void {
        timestampRoundTrip('students', static fn () => (new MySQLStudentRepository(MySQLTestSupport::connectApp()))->findById(1),
            'Invalid stored student.');
    });
    test('T-60 V9 MySQL (faculty): a zero updated_at row fails closed, generic and unchained', static function (): void {
        timestampRoundTrip('faculty', static fn () => (new MySQLFacultyRepository(MySQLTestSupport::connectApp()))->findById(1),
            'Invalid stored faculty.');
    });
}
