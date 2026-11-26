<?php
declare(strict_types=1);

// CLI worker: native sessions need a process with no prior output.
// Usage: php session_clock.php <runtime-dir> <idle|absolute>
require dirname(__DIR__, 3) . '/app/autoload.php';

use App\Auth\Session;

if (PHP_SAPI !== 'cli' || $argc !== 3 || !in_array($argv[2], ['idle', 'absolute'], true)) {
    exit(2);
}
$now = 1_000_000;
$session = new Session($argv[1], false, static function () use (&$now): int { return $now; });
$session->login(7, 3);
$results = [];
$results['fresh'] = $session->identity();

if ($argv[2] === 'idle') {
    $now += Session::IDLE_TIMEOUT - 1;
    $results['before'] = $session->identity();
    $now += Session::IDLE_TIMEOUT;
} else {
    // Stay active (each check refreshes last_seen) until the absolute limit passes.
    for ($step = 0; $now + 1500 < 1_000_000 + Session::ABSOLUTE_TIMEOUT; $step++) {
        $now += 1500;
        $results['before'] = $session->identity();
    }
    $now = 1_000_000 + Session::ABSOLUTE_TIMEOUT;
}
$results['after'] = $session->identity();
$results['after_again'] = $session->identity();
echo json_encode($results, JSON_THROW_ON_ERROR);
