<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/autoload.php';

use App\Core\JsonStore;

if (PHP_SAPI !== 'cli' || $argc !== 4) {
    exit(2);
}

file_put_contents($argv[3], 'ready');
$deadline = microtime(true) + 10;
while (!is_file($argv[2])) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Start barrier timed out\n");
        exit(1);
    }
    usleep(10000);
}
$store = new JsonStore($argv[1]);
for ($index = 0; $index < 50; $index++) {
    $store->transaction(function (array &$data): void {
        $current = $data['count'];
        usleep(1000);
        $data['count'] = $current + 1;
    });
}
echo '50 updates', PHP_EOL;
