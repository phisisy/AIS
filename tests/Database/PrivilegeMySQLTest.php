<?php
declare(strict_types=1);

require_once __DIR__ . '/MySQLTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/tools/MigrationRunner.php';

use App\Core\Database;
use Database\Tools\MigrationRunner;

if (MySQLTestSupport::enabled() && is_string(getenv('AIS_MYSQL_TEST_APP_PASSWORD'))) {
    test('MySQL app grants keep history append-only and prohibit hard deletes', static function (): void {
        $tester = MySQLTestSupport::connect();
        MySQLTestSupport::reset($tester);
        (new MigrationRunner($tester, 'ais_test', dirname(__DIR__, 2) . '/database/migrations'))->up();

        $app = Database::connect([
            'host' => getenv('DB_HOST'),
            'port' => getenv('DB_PORT'),
            'database' => 'ais_test',
            'username' => 'ais_test_app',
            'password' => getenv('AIS_MYSQL_TEST_APP_PASSWORD'),
        ]);
        MySQLTestSupport::assertConnection($app);
        check($app->query('SELECT COUNT(*) FROM `ais_test`.`schema_migrations`')->fetchColumn() !== false);
        foreach ([
            'UPDATE `ais_test`.`audit_logs` SET outcome=outcome WHERE id=0',
            'DELETE FROM `ais_test`.`audit_logs` WHERE id=0',
            'UPDATE `ais_test`.`student_academic_history` SET to_value=to_value WHERE seq=0',
            'DELETE FROM `ais_test`.`student_academic_history` WHERE seq=0',
            'UPDATE `ais_test`.`section_instructor_history` SET action=action WHERE seq=0',
            'DELETE FROM `ais_test`.`section_instructor_history` WHERE seq=0',
            'UPDATE `ais_test`.`faculty_history` SET field=field WHERE seq=0',
            'DELETE FROM `ais_test`.`faculty_history` WHERE seq=0',
            'DELETE FROM `ais_test`.`enrollments` WHERE id=0',
            'UPDATE `ais_test`.`enrollment_history` SET action=action WHERE seq=0',
            'DELETE FROM `ais_test`.`enrollment_history` WHERE seq=0',
            'DELETE FROM `ais_test`.`grades` WHERE id=0',
            'UPDATE `ais_test`.`grade_history` SET action=action WHERE seq=0',
            'DELETE FROM `ais_test`.`grade_history` WHERE seq=0',
            'DELETE FROM `ais_test`.`users` WHERE id=0',
            'DELETE FROM `ais_test`.`students` WHERE id=0',
            'DELETE FROM `ais_test`.`faculty` WHERE id=0',
        ] as $sql) {
            $exception = throws(PDOException::class, static fn () => $app->exec($sql));
            same(1142, (int) $exception->errorInfo[1]);
        }
    });

    // DB-14: SHOW GRANTS also lists what an account inherits from the MariaDB 10.11 PUBLIC role. install-db grants
    // PUBLIC broad rights on `test` and `test\_%` (CREATE, DROP, ALTER, routines, events), and every account gets them.
    // Read-only on purpose: no CREATE DATABASE probe, so a failing run cannot create anything outside ais_test.
    test('DB-14 MySQL: app and tester reach no database but ais_test, including grants inherited from PUBLIC', static function (): void {
        $app = MySQLTestSupport::connectApp();
        $tester = MySQLTestSupport::connect();
        foreach (['app' => $app, 'tester' => $tester] as $name => $pdo) {
            $grants = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
            check($grants !== [], "No grants listed for {$name}.");
            foreach ($grants as $grant) {
                $allowed = preg_match('/\AGRANT USAGE ON \*\.\* TO `ais_test(_app|er)`@`127\.0\.0\.1`/', $grant) === 1
                    || preg_match('/\AGRANT [A-Z, ]+ ON `ais_test`\.(`[a-z_]+`|\*) TO `ais_test(_app|er)`@`127\.0\.0\.1`\z/', $grant) === 1;
                // Never print an auth string (PASSWORD '*…' or IDENTIFIED VIA … USING '…'): keep only the text before it.
                check($allowed, "{$name} holds a grant outside ais_test: " . explode(' IDENTIFIED', $grant, 2)[0]);
                check(!str_contains($grant, 'WITH GRANT OPTION'), "{$name} holds GRANT OPTION.");
            }
            same(['ais_test', 'information_schema'], $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN));
            // USE checks privileges before existence: 1044 = no privilege. 1049 (unknown database) would mean a
            // matching grant is still in effect, so only 1044 passes. The random name probes the `test\_%` pattern
            // without creating anything.
            foreach (['test', 'test_ais_probe_' . bin2hex(random_bytes(6))] as $schema) {
                $refused = throws(PDOException::class, static fn () => $pdo->exec('USE `' . $schema . '`'));
                same(1044, (int) $refused->errorInfo[1]);
            }
        }
    });
}
