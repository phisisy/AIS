<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Environment;
use Database\Tools\ImportRefused;
use Database\Tools\JsonImporter;

require dirname(__DIR__) . '/app/autoload.php';
require __DIR__ . '/tools/IntegrityVerifier.php';
require __DIR__ . '/tools/MigrationRunner.php';
require __DIR__ . '/tools/JsonImporter.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit(2); }
try {
    $options = JsonImporter::parseArguments($argv);
    Environment::load(dirname(__DIR__) . '/.env');
    JsonImporter::assertOfflineTarget($options, [
        'database'=>Environment::get('DB_DATABASE', ''),
        'host'=>Environment::get('DB_HOST', ''),
        'port'=>Environment::get('DB_PORT', ''),
        'username'=>Environment::get('DB_MIGRATION_USERNAME', ''),
    ]);
    $pdo = Database::connect(['host'=>Environment::get('DB_HOST', ''),
        'port'=>Environment::get('DB_PORT', ''), 'database'=>'ais_test',
        'username'=>Environment::get('DB_MIGRATION_USERNAME', ''),
        'password'=>Environment::get('DB_MIGRATION_PASSWORD', '')]);
    $importer = new JsonImporter($pdo, __DIR__ . '/migrations');
    $counts = $importer->import($options);
    foreach ($counts as $table => $count) { echo $table . ' ' . $count . PHP_EOL; }
    echo $options['dry_run'] ? "DRY RUN ROLLED BACK\n" : "IMPORT COMMITTED\n";
    echo "Reminder: stop the app for the whole import.\n";
    exit(0);
} catch (ImportRefused $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
} catch (Throwable) {
    fwrite(STDERR, "Import aborted; no module data committed.\n");
    exit(1);
}
