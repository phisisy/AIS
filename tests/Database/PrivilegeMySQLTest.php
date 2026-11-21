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
            'DELETE FROM `ais_test`.`users` WHERE id=0',
            'DELETE FROM `ais_test`.`students` WHERE id=0',
            'DELETE FROM `ais_test`.`faculty` WHERE id=0',
        ] as $sql) {
            $exception = throws(PDOException::class, static fn () => $app->exec($sql));
            same(1142, (int) $exception->errorInfo[1]);
        }
    });
}
