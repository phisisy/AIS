<?php
declare(strict_types=1);

use App\Core\Database;

/** The online suite is opt-in and can only modify the dedicated test schema. */
final class MySQLTestSupport
{
    private const DROP_ORDER = [
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
            || !in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)
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
        }
    }

    private static function isTestAccount(string $account): bool
    {
        return str_starts_with($account, 'ais_tester@')
            || str_starts_with($account, 'ais_test_app@');
    }
}
