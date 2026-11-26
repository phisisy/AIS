<?php
declare(strict_types=1);

require_once __DIR__ . '/MySQLTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/tools/MigrationRunner.php';

use Database\Tools\MigrationRefused;
use Database\Tools\MigrationRunner;

if (MySQLTestSupport::enabled()) {
    test('MySQL migrations apply in order, repeat as no-op, and reject checksum changes', static function (): void {
        $pdo = MySQLTestSupport::connect();
        MySQLTestSupport::reset($pdo);
        $source = dirname(__DIR__, 2) . '/database/migrations';
        $scratch = tempDirectory();
        try {
            foreach (glob($source . '/V*.sql') as $file) {
                check(copy($file, $scratch . '/' . basename($file)), 'Unable to copy migration fixture.');
            }
            $runner = new MigrationRunner($pdo, 'ais_test', $scratch);
            $applied = $runner->up();
            same([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], array_map('intval', array_column($applied, 'version')));
            same([], $runner->up());
            $status = $runner->status();
            same([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], array_map('intval', array_column($status, 'version')));
            foreach ($status as $row) {
                same('applied', $row['state']);
            }
            $file = $scratch . '/V0002__auth.sql';
            check(file_put_contents($file, "\n-- tampered\n", FILE_APPEND) !== false);
            throws(MigrationRefused::class, static fn () => $runner->up());
        } finally {
            removeTestDirectory($scratch);
        }
    });
}
