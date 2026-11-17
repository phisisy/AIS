<?php
declare(strict_types=1);

use App\Auth\Repositories\MockUserRepository;
use App\Core\JsonStore;

require_once __DIR__ . '/AdminSupport.php';

/**
 * Starts one worker per job, releases them together, and returns their results.
 * Each job: [actorId, targetId, 'deactivate'|'demote'].
 */
function raceAdminWorkers(string $directory, array $jobs): array
{
    $workers = [];
    $results = [];
    $start = $directory . '/start';
    @unlink($start);
    array_map('unlink', glob($directory . '/ready-*') ?: []);
    try {
        foreach ($jobs as $index => [$actor, $target, $action]) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/admin_worker.php', $directory . '/users.json',
                $start, $directory . '/ready-' . $index, (string) $actor, (string) $target, $action],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            check(is_resource($process), 'Unable to start admin worker.');
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 10;
        while (count(glob($directory . '/ready-*') ?: []) < count($jobs)) {
            check(microtime(true) < $deadline, 'Admin workers did not become ready.');
            usleep(5000);
        }
        file_put_contents($start, 'go');
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            same(0, proc_close($process));
            same('', $errors);
            $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        }
        $workers = [];
        return $results;
    } finally {
        foreach ($workers as [$process, $pipes]) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
}

test('Concurrent cross-removal of the only two admins: exactly one wins, one admin always remains', function (): void {
    foreach ([['deactivate', 'deactivate'], ['demote', 'deactivate'], ['demote', 'demote']] as $round => [$first, $second]) {
        $directory = tempDirectory();
        try {
            [, $repository] = adminFixture($directory);
            $results = raceAdminWorkers($directory, [[1, 2, $first], [2, 1, $second]]);
            $wins = array_values(array_filter($results, static fn (array $r): bool => $r['ok']));
            check(count($wins) === 1, "Round {$round}: expected exactly one success, got " . json_encode($results));
            $loser = array_values(array_filter($results, static fn (array $r): bool => !$r['ok']))[0];
            // The loser either finds itself no longer an admin (401/403) or hits the invariant (409).
            check(in_array($loser['status'], [401, 403, 409], true), 'Unexpected loser status ' . $loser['status']);
            same(1, activeAdminCount($repository));
            same(1, auditCount($repository, 'success'));
            same(1, auditCount($repository, 'denied'));
        } finally {
            removeTestDirectory($directory);
        }
    }
});

test('Concurrent three-way admin removals never leave zero active admins and audit only real changes', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $directory = tempDirectory();
        try {
            $rows = adminTestRows();
            $rows[2]['roles'] = ['admin']; // id 3 becomes a third active admin
            (new MockUserRepository(new JsonStore($directory . '/users.json')))->seed($rows);
            $repository = new MockUserRepository(new JsonStore($directory . '/users.json'));
            $results = raceAdminWorkers($directory, [[1, 2, 'deactivate'], [2, 3, 'demote'], [3, 1, 'deactivate']]);
            $wins = count(array_filter($results, static fn (array $r): bool => $r['ok']));
            check($wins >= 1 && $wins <= 2, 'Expected one or two successes, got ' . json_encode($results));
            check(activeAdminCount($repository) >= 1, 'No active admin left: ' . json_encode($results));
            same($wins, auditCount($repository, 'success'));
            same(3 - $wins, auditCount($repository, 'denied'));
        } finally {
            removeTestDirectory($directory);
        }
    }
});
