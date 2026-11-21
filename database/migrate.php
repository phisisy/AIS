<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Environment;
use Database\Tools\MigrationRefused;
use Database\Tools\MigrationError;
use Database\Tools\MigrationRunner;

require dirname(__DIR__) . '/app/autoload.php';
require __DIR__ . '/tools/MigrationRunner.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(2);
}

try {
    if ($argc !== 3 || !in_array($argv[1], ['status', 'up'], true)
        || !preg_match('/\A--target=([A-Za-z0-9_]+)\z/D', $argv[2], $match)) {
        throw new MigrationRefused('Usage: php database/migrate.php status|up --target=<db>');
    }
    Environment::load(dirname(__DIR__) . '/.env');
    $target = $match[1];
    $database = Environment::get('DB_DATABASE', '');
    // Phase 5 is authorized against ais_test only.
    if ($target !== 'ais_test' || $database !== $target) {
        throw new MigrationRefused('Target refused.');
    }
    $username = Environment::get('DB_MIGRATION_USERNAME', '');
    if ($username === '' || strtolower($username) === 'root') {
        throw new MigrationRefused('Migration account refused.');
    }
    $host = Environment::get('DB_HOST', '127.0.0.1');
    if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new MigrationRefused('Migration host refused.');
    }
    $pdo = Database::connect([
        'host' => $host,
        'port' => Environment::get('DB_PORT', '3306'),
        'database' => $database,
        'username' => $username,
        'password' => Environment::get('DB_MIGRATION_PASSWORD', ''),
    ]);
    $runner = new MigrationRunner($pdo, $target, __DIR__ . '/migrations');
    $rows = $argv[1] === 'up' ? $runner->up() : $runner->status();
    foreach ($rows as $row) {
        printf("V%04d %s%s\n", $row['version'], $row['name'], isset($row['state']) ? ' ' . $row['state'] : '');
    }
    if ($rows === []) {
        echo "No changes.\n";
    }
    exit(0);
} catch (MigrationRefused $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(2);
} catch (MigrationError $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Migration error.\n");
    exit(1);
}
