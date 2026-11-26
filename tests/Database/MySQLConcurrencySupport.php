<?php
declare(strict_types=1);

use Database\Tools\MigrationRunner;

require_once __DIR__ . '/MySQLTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/tools/MigrationRunner.php';

function mysqlConcurrencyEnabled(): bool
{
    return getenv('AIS_MYSQL_TESTS') === '1'
        && is_string(getenv('AIS_MYSQL_TEST_APP_PASSWORD'))
        && MySQLTestSupport::enabled();
}

/** Reset only the guarded test schema and seed all referenced identities. */
function mysqlConcurrencyFixture(): PDO
{
    $owner = MySQLTestSupport::connect();
    check(str_starts_with((string) $owner->query('SELECT CURRENT_USER()')->fetchColumn(), 'ais_tester@'),
        'Concurrency fixture requires ais_tester.');
    MySQLTestSupport::reset($owner);
    (new MigrationRunner($owner, 'ais_test', dirname(__DIR__, 2) . '/database/migrations'))->up();
    $hash = password_hash('Concurrency test passphrase', PASSWORD_DEFAULT);
    $user = $owner->prepare('INSERT INTO users (id, email, status, password_hash, auth_version) VALUES (?, ?, ?, ?, 1)');
    $role = $owner->prepare('INSERT INTO user_roles (user_id, role) VALUES (?, ?)');
    $faculty = $owner->prepare('INSERT INTO faculty (id, user_id, employee_number, first_name, last_name, department, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    for ($id = 1; $id <= 12; $id++) {
        $user->execute([$id, "race{$id}@example.test", 'active', $hash]);
        $role->execute([$id, $id <= 2 ? 'admin' : ($id <= 8 ? 'faculty' : 'student')]);
        if ($id <= 8) {
            $faculty->execute([$id, $id, "RACE{$id}", 'Faculty', "Number{$id}", 'Science', 'active',
                '2025-01-01 03:04:05', '2025-01-01 03:04:05']);
        }
    }
    $program = $owner->prepare('INSERT INTO programs (code, name, max_year_level) VALUES (?, ?, ?)');
    $program->execute(['BSCS', 'Computer Science', 4]);
    $program->execute(['BSIT', 'Information Technology', 4]);
    return $owner;
}

/** Runs jobs concurrently while the tester holds their store lock. */
function mysqlRace(PDO $owner, string $lockName, array $jobs, int $lockWait = 5): array
{
    $dir = tempDirectory();
    $workers = [];
    try {
        $owner->beginTransaction();
        $lock = $owner->prepare('SELECT last_seq FROM repository_locks WHERE name = ? FOR UPDATE');
        $lock->execute([$lockName]);
        check($lock->fetchColumn() !== false, 'Missing repository lock.');
        foreach ($jobs as $i => $job) {
            $out = $dir . '/out-' . $i;
            $err = $dir . '/err-' . $i;
            $command = [PHP_BINARY, __DIR__ . '/fixtures/mysql_worker.php', $dir . '/start',
                $dir . '/ready-' . $i, $dir . '/attempt-' . $i,
                json_encode($job, JSON_THROW_ON_ERROR), (string) $lockWait];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'],
                2 => ['file', $err, 'w']], $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
            check(is_resource($process), 'Unable to start MySQL worker.');
            fclose($pipes[0]);
            $workers[] = [$process, $out, $err];
        }
        mysqlWaitForFlags($dir, 'ready-', count($jobs), 10);
        file_put_contents($dir . '/start', 'go');
        mysqlWaitForFlags($dir, 'attempt-', count($jobs), 10);
        // A bounded live-process poll establishes that all calls are pending while
        // the tester owns the lock. It does not claim a particular worker order.
        $until = microtime(true) + 0.15;
        do {
            foreach ($workers as [$process]) {
                check(proc_get_status($process)['running'], 'Worker finished before lock release.');
            }
            usleep(5000);
        } while (microtime(true) < $until);
        $owner->commit();
        $results = mysqlCollectWorkers($workers, 15);
        mysqlRecordCompletionOrder($results, $lockName);
        return $results;
    } finally {
        if ($owner->inTransaction()) {
            $owner->rollBack();
        }
        foreach ($workers as [$process]) {
            if (is_resource($process)) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process);
                }
                proc_close($process);
            }
        }
        removeTestDirectory($dir);
    }
}

function mysqlWaitForFlags(string $dir, string $prefix, int $count, int $seconds): void
{
    $deadline = microtime(true) + $seconds;
    while (count(glob($dir . '/' . $prefix . '*') ?: []) < $count) {
        check(microtime(true) < $deadline, 'MySQL worker barrier timed out.');
        usleep(5000);
    }
}

function mysqlCollectWorkers(array &$workers, int $seconds): array
{
    $deadline = microtime(true) + $seconds;
    $results = [];
    foreach ($workers as &$worker) {
        [$process, $out, $err] = $worker;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            check(microtime(true) < $deadline, 'MySQL worker hard timeout.');
            usleep(5000);
        } while (true);
        $exit = $status['exitcode'];
        $closed = proc_close($process);
        $worker[0] = null;
        same(0, $exit === -1 ? $closed : $exit);
        same('', file_get_contents($err));
        $lines = explode("\n", trim((string) file_get_contents($out)));
        same(1, count($lines));
        $result = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        check(is_array($result) && is_bool($result['ok'] ?? null), 'Invalid MySQL worker result.');
        $results[] = $result;
    }
    unset($worker);
    return $results;
}

function mysqlWinnerCount(array $results): int
{
    return count(array_filter($results, static fn (array $result): bool => $result['ok']));
}

function mysqlRecordCompletionOrder(array $results, string $label): void
{
    $order = array_keys($results);
    usort($order, static fn (int $a, int $b): int => ($results[$a]['completed_at'] <=> $results[$b]['completed_at']) ?: ($a <=> $b));
    echo 'MYSQL COMPLETION ORDER ' . $label . ': ' . implode(',', $order) . "\n";
}

function mysqlSequence(PDO $owner, string $sql): array
{
    return array_map('intval', $owner->query($sql)->fetchAll(PDO::FETCH_COLUMN));
}
