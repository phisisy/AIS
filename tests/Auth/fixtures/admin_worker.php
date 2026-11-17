<?php
declare(strict_types=1);

// CLI worker for concurrency tests: one admin operation as an already-authenticated actor.
// Usage: php admin_worker.php <users.json> <start-flag> <ready-flag> <actorId> <targetId> <deactivate|demote>
require dirname(__DIR__, 3) . '/app/autoload.php';

use App\Auth\Repositories\MockUserRepository;
use App\Auth\Services\AuthorizationService;
use App\Auth\Services\LoginRateLimiter;
use App\Auth\Services\UserService;
use App\Core\HttpException;
use App\Core\JsonStore;

if (PHP_SAPI !== 'cli' || $argc !== 7 || !in_array($argv[6], ['deactivate', 'demote'], true)) {
    exit(2);
}
$repository = new MockUserRepository(new JsonStore($argv[1]));
$service = new UserService($repository, new AuthorizationService(),
    new LoginRateLimiter(new JsonStore(dirname($argv[1]) . '/attempts-' . getmypid() . '.json')));
// Loaded before the race, exactly as the route guard would have done for this request.
$actor = $repository->findById((int) $argv[4]);
file_put_contents($argv[3], 'ready');
$deadline = microtime(true) + 10;
while (!is_file($argv[2])) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Start barrier timed out\n");
        exit(1);
    }
    usleep(1000);
}
try {
    $argv[6] === 'deactivate'
        ? $service->changeStatus($actor, (int) $argv[5], ['status' => 'inactive'])
        : $service->update($actor, (int) $argv[5], ['roles' => ['faculty']]);
    echo json_encode(['ok' => true, 'status' => 200]);
} catch (HttpException $exception) {
    echo json_encode(['ok' => false, 'status' => $exception->status, 'message' => $exception->getMessage()]);
}
