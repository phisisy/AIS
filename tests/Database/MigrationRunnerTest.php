<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/tools/MigrationRunner.php';

use Database\Tools\MigrationRefused;
use Database\Tools\MigrationRunner;

test('Migration runner splits only line-ended statements and ignores recovery comments', function (): void {
    $sql = "-- MANUAL RECOVERY: DROP TABLE example;\nCREATE TABLE example (\n id INT\n);\n"
        . "-- comment\nINSERT INTO example (id) VALUES (1);\n";
    same(['CREATE TABLE example (' . "\n" . 'id INT' . "\n" . ')', 'INSERT INTO example (id) VALUES (1)'],
        MigrationRunner::splitStatements($sql));
    throws(MigrationRefused::class, static fn () => MigrationRunner::splitStatements("SELECT 1; SELECT 2;\n"));
    throws(MigrationRefused::class, static fn () => MigrationRunner::splitStatements("SELECT 1\n"));
});

test('Migration runner requires contiguous ordered files and matching checksums', function (): void {
    $directory = tempDirectory();
    try {
        file_put_contents($directory . '/V0001__first.sql', "SELECT 1;\n");
        file_put_contents($directory . '/V0002__second.sql', "SELECT 2;\n");
        $files = MigrationRunner::discoverFiles($directory);
        same([1, 2], array_keys($files));
        $rows = [['version' => 1, 'name' => 'first', 'checksum' => $files[1]['checksum'],
            'state' => 'applied', 'statements_applied' => 1]];
        MigrationRunner::validateHistory($files, $rows);
        $tampered = $rows;
        $tampered[0]['checksum'] = str_repeat('0', 64);
        throws(MigrationRefused::class, static fn () => MigrationRunner::validateHistory($files, $tampered));
        $started = $rows;
        $started[0]['state'] = 'started';
        throws(MigrationRefused::class, static fn () => MigrationRunner::validateHistory($files, $started));
        $outOfOrder = [['version' => 2, 'name' => 'second', 'checksum' => $files[2]['checksum'],
            'state' => 'applied', 'statements_applied' => 1]];
        throws(MigrationRefused::class, static fn () => MigrationRunner::validateHistory($files, $outOfOrder));
        file_put_contents($directory . '/V0004__gap.sql', "SELECT 4;\n");
        throws(MigrationRefused::class, static fn () => MigrationRunner::discoverFiles($directory));
    } finally {
        removeTestDirectory($directory);
    }
});

test('Migration runner confines faculty versions to ais_test', function (): void {
    MigrationRunner::assertFacultyTarget(4, 'other');
    MigrationRunner::assertFacultyTarget(5, 'ais_test');
    MigrationRunner::assertFacultyTarget(6, 'ais_test');
    throws(MigrationRefused::class, static fn () => MigrationRunner::assertFacultyTarget(5, 'other'));
    throws(MigrationRefused::class, static fn () => MigrationRunner::assertFacultyTarget(6, 'other'));
});

test('All shipped migrations parse in dependency order without executing SQL', function (): void {
    $files = MigrationRunner::discoverFiles(dirname(__DIR__, 2) . '/database/migrations');
    same([1, 2, 3, 4, 5, 6], array_keys($files));
    $counts = [];
    foreach ($files as $file) {
        $counts[] = count(MigrationRunner::splitStatements($file['sql']));
    }
    same([1, 4, 4, 4, 3, 2], $counts);
});
