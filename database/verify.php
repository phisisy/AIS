<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Environment;
use Database\Tools\IntegrityVerifier;

require dirname(__DIR__) . '/app/autoload.php';
require __DIR__ . '/tools/IntegrityVerifier.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit(2); }
try {
    if ($argc !== 2 || $argv[1] !== '--target=ais_test') { throw new InvalidArgumentException('Target refused.'); }
    Environment::load(dirname(__DIR__) . '/.env');
    if (Environment::get('DB_DATABASE', '') !== 'ais_test'
        || !in_array(Environment::get('DB_HOST', ''), ['127.0.0.1','localhost','::1'], true)) {
        throw new InvalidArgumentException('Target refused.');
    }
    $port = Environment::get('DB_PORT', '');
    if (!is_string($port) || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new InvalidArgumentException('Target refused.');
    }
    $username = Environment::get('DB_USERNAME', '');
    if (!in_array($username, ['ais_tester','ais_test_app'], true)) { throw new InvalidArgumentException('Account refused.'); }
    $pdo = Database::connect(['host'=>Environment::get('DB_HOST', ''), 'port'=>$port,
        'database'=>'ais_test', 'username'=>$username, 'password'=>Environment::get('DB_PASSWORD', '')]);
    $live = $pdo->query('SELECT DATABASE() AS db_name, @@port AS server_port, CURRENT_USER() AS account')->fetch();
    if (!is_array($live) || $live['db_name'] !== 'ais_test' || (string)$live['server_port'] !== $port
        || !str_starts_with((string)$live['account'], $username . '@')) {
        throw new InvalidArgumentException('Connection refused.');
    }
    $checks = (new IntegrityVerifier($pdo))->verify();
    $failed = false;
    foreach ($checks as $name => $ids) {
        $numericIds = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        echo $name . ' ' . ($ids === [] ? 'PASS' : 'FAIL' . ($numericIds === [] ? '' : ' ' . implode(',', $numericIds))) . PHP_EOL;
        $failed = $failed || $ids !== [];
    }
    exit($failed ? 1 : 0);
} catch (Throwable) {
    fwrite(STDERR, "Verification error.\n");
    exit(2);
}
