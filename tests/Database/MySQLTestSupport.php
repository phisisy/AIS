<?php
declare(strict_types=1);

use App\Core\Database;

/** The online suite is opt-in and can only modify the dedicated test schema. */
final class MySQLTestSupport
{
    private const DROP_ORDER = [
        'grade_history', 'grades',
        'enrollment_history', 'enrollments',
        'section_instructors', 'section_instructor_history', 'faculty_history',
        'faculty', 'section_meetings', 'sections', 'terms',
        'student_academic_history', 'students', 'programs', 'audit_logs',
        'user_roles', 'users', 'repository_locks', 'schema_migrations',
    ];

    public static function enabled(): bool
    {
        try {
            self::connect();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function assertEnvironment(): void
    {
        if (getenv('AIS_MYSQL_TESTS') !== '1'
            || getenv('APP_ENV') !== 'testing'
            || getenv('DB_DATABASE') !== 'ais_test'
            || getenv('AIS_MYSQL_TEST_TARGET') !== 'ais_test'
            || !in_array(getenv('DB_HOST'), ['127.0.0.1'], true)
            || !is_string(getenv('DB_PORT'))
            || !ctype_digit((string) getenv('DB_PORT'))
            || (int) getenv('DB_PORT') < 1
            || (int) getenv('DB_PORT') > 65535
            || !in_array(getenv('DB_USERNAME'), ['ais_tester', 'ais_test_app'], true)
            || !is_string(getenv('DB_PASSWORD'))) {
            throw new RuntimeException('Unsafe MySQL test target.');
        }
    }

    public static function connect(): PDO
    {
        self::assertEnvironment();
        $pdo = Database::connect([
            'host' => getenv('DB_HOST'),
            'port' => getenv('DB_PORT'),
            'database' => getenv('DB_DATABASE'),
            'username' => getenv('DB_USERNAME'),
            'password' => getenv('DB_PASSWORD'),
        ]);
        self::assertConnection($pdo);
        return $pdo;
    }

    public static function connectApp(): PDO
    {
        self::assertEnvironment();
        $password = getenv('AIS_MYSQL_TEST_APP_PASSWORD');
        if (!is_string($password)) {
            throw new RuntimeException('AIS_MYSQL_TEST_APP_PASSWORD is required for MySQL contract tests.');
        }
        $pdo = Database::connect([
            'host' => getenv('DB_HOST'),
            'port' => getenv('DB_PORT'),
            'database' => getenv('DB_DATABASE'),
            'username' => 'ais_test_app',
            'password' => $password,
        ]);
        self::assertConnection($pdo);
        $statement = $pdo->prepare('SELECT CURRENT_USER()');
        $statement->execute();
        if ((string) $statement->fetchColumn() !== 'ais_test_app@127.0.0.1') {
            throw new RuntimeException('Unsafe MySQL app test connection.');
        }
        return $pdo;
    }

    public static function assertConnection(PDO $pdo): void
    {
        self::assertEnvironment();
        $live = $pdo->query('SELECT DATABASE() AS db_name, @@port AS server_port, CURRENT_USER() AS account')->fetch();
        if (!is_array($live)
            || $live['db_name'] !== 'ais_test'
            || (string) $live['server_port'] !== getenv('DB_PORT')
            || !self::isTestAccount((string) $live['account'])) {
            throw new RuntimeException('Unsafe MySQL test connection.');
        }
    }

    public static function assertBeforeReset(PDO $pdo): void
    {
        // This is deliberately a fresh read on the connection used for the next DROP.
        self::assertConnection($pdo);
    }

    public static function reset(PDO $pdo): void
    {
        foreach (self::DROP_ORDER as $table) {
            self::assertBeforeReset($pdo);
            $pdo->exec('DROP TABLE IF EXISTS `ais_test`.`' . $table . '`');
            // Leftovers of httpMoveTables() after an interrupted run.
            self::assertBeforeReset($pdo);
            $pdo->exec('DROP TABLE IF EXISTS `ais_test`.`' . $table . '__moved`');
        }
    }

    /**
     * Raw-seeded fixtures must still be VALID data for the 35-check verifier (trusted run 2026-11-26: users without roles
     * and students without history were reported as user_model, user_roles and student_history_replay). Gives every user
     * without a role the role in $roleByUser, and every student without history the three creation entries
     * (program_code, year_level, academic_status from null), as the repository writes them. Nothing else changes.
     * @param array<int, string> $roleByUser
     */
    public static function completeFixture(PDO $owner, array $roleByUser): void
    {
        self::assertConnection($owner);
        $role = $owner->prepare('INSERT INTO user_roles (user_id, role) SELECT ?, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM user_roles WHERE user_id = ?)');
        foreach ($roleByUser as $userId => $name) {
            $role->execute([$userId, $name, $userId]);
        }
        $history = $owner->prepare('INSERT INTO student_academic_history (student_id, seq, at, field, from_value, to_value, actor_id) VALUES (?, ?, ?, ?, NULL, ?, 1)');
        foreach ($owner->query('SELECT s.id, s.program_code, s.year_level, s.academic_status, s.created_at FROM students s '
            . 'WHERE NOT EXISTS (SELECT 1 FROM student_academic_history h WHERE h.student_id = s.id) ORDER BY s.id')->fetchAll(PDO::FETCH_ASSOC) as $s) {
            foreach ([['program_code', $s['program_code']], ['year_level', (string) $s['year_level']], ['academic_status', $s['academic_status']]] as $i => [$field, $to]) {
                $history->execute([$s['id'], $i + 1, $s['created_at'], $field, $to]);
            }
        }
    }

    private static function isTestAccount(string $account): bool
    {
        return in_array($account, ['ais_tester@127.0.0.1', 'ais_test_app@127.0.0.1'], true);
    }
}
