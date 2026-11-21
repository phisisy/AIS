<?php
declare(strict_types=1);

use App\Auth\Repositories\MySQLUserRepository;
use App\Auth\Repositories\UserTransactionInterface;
use App\Core\HttpException;
use App\Enrollment\Repositories\MySQLCatalogRepository;
use App\Students\Repositories\MySQLStudentRepository;

require dirname(__DIR__, 3) . '/app/autoload.php';
require dirname(__DIR__) . '/MySQLTestSupport.php';

if (PHP_SAPI !== 'cli' || $argc !== 6) {
    exit(2);
}

$app = null;
$tester = null;
$driverCode = null;
try {
    $job = json_decode($argv[4], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($job) || !is_string($job['op'] ?? null)) {
        throw new InvalidArgumentException('Invalid job.');
    }
    $app = MySQLTestSupport::connectApp();
    $limit = (int) $argv[5];
    if ($limit < 1 || $limit > 10) {
        throw new InvalidArgumentException('Invalid lock timeout.');
    }
    $app->exec('SET SESSION innodb_lock_wait_timeout = ' . $limit);
    if (str_starts_with($job['op'], 'deadlock_')) {
        // The app account cannot take arbitrary store locks. The deliberately
        // inverted lock order is injected through the guarded tester connection.
        $tester = MySQLTestSupport::connect();
        if (!str_starts_with((string) $tester->query('SELECT CURRENT_USER()')->fetchColumn(), 'ais_tester@')) {
            throw new RuntimeException('Tester account required.');
        }
        $tester->exec('SET SESSION innodb_lock_wait_timeout = ' . $limit);
    }
    file_put_contents($argv[2], 'ready');
    $deadline = microtime(true) + 10;
    while (!is_file($argv[1])) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Start barrier timed out.');
        }
        usleep(5000);
    }
    file_put_contents($argv[3], 'attempt');
    $auth = new MySQLUserRepository($tester ?? $app);
    $students = new MySQLStudentRepository($tester ?? $app);
    $catalog = new MySQLCatalogRepository($app);
    $now = '2025-01-02T03:04:05Z';
    $op = $job['op'];
    match ($op) {
        'auth_update' => $auth->transaction(static function (UserTransactionInterface $tx) use ($job, $now): void {
            $tx->update($job['id'], $job['changes']);
            if ($job['audit'] ?? false) {
                $tx->appendAudit(1, $job['id'], 'user.updated', $job['changes'], $now);
            }
        }),
        'auth_fail_after_audit' => $auth->transaction(static function (UserTransactionInterface $tx) use ($job, $now): void {
            $tx->update($job['id'], $job['changes']);
            $tx->appendAudit(1, $job['id'], 'user.updated', $job['changes'], $now);
            throw new RuntimeException('Injected failure.');
        }),
        'auth_timeout_probe' => mysqlWorkerTimeoutProbe($auth, $app, $job, $now, $driverCode),
        'auth_commit_then_write' => mysqlWorkerFollowUp($auth, false, $now),
        'auth_rollback_then_write' => mysqlWorkerFollowUp($auth, true, $now),
        'student_create' => $students->create($job['fields'], $now, 1),
        'student_update' => $students->update($job['id'], $job['changes'], $now, null, 1),
        'student_check_fail' => $students->update($job['id'], $job['changes'], $now,
            static function (): void { throw new RuntimeException('Injected failure.'); }, 1),
        'term_current' => $catalog->updateTerm($job['id'], ['is_current' => true], $now),
        'section_update' => $catalog->updateSection($job['id'], $job['changes'], $now),
        'assign' => $catalog->assignInstructor($job['id'], $job['faculty_id'], $now, 1),
        'unassign' => $catalog->unassignInstructor($job['id'], $job['faculty_id'], $now, 1),
        'deadlock_auth' => $auth->transaction(static function (UserTransactionInterface $tx) use ($tester, $job, $now, &$driverCode): void {
            $tx->update(3, ['email' => 'deadlock-auth@example.test']);
            $tx->appendAudit(1, 3, 'user.updated', ['email' => 'deadlock-auth@example.test'], $now);
            mysqlWorkerCrossLock($tester, $job, 'students', $driverCode);
        }),
        'deadlock_student' => $students->update(1, ['year_level' => 2], $now,
            static function () use ($tester, $job, &$driverCode): void {
                mysqlWorkerCrossLock($tester, $job, 'auth', $driverCode);
            }, 1),
        default => throw new InvalidArgumentException('Invalid operation.'),
    };
    $result = ['ok' => true, 'status' => 200];
} catch (HttpException $error) {
    $result = ['ok' => false, 'status' => $error->status, 'message' => $error->getMessage(),
        'errors' => $error->errors, 'class' => HttpException::class];
} catch (Throwable $error) {
    $allowed = ['User repository unavailable.', 'Student repository unavailable.',
        'Catalog repository unavailable.', 'Injected failure.'];
    $result = ['ok' => false, 'status' => 500,
        'message' => in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'Worker failed.',
        'class' => get_class($error)];
    if ($driverCode !== null) {
        $result['driver_code'] = $driverCode;
    }
} finally {
    foreach ([$tester, $app] as $pdo) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable) { /* Connection cleanup. */ }
        }
    }
    $tester = null;
    $app = null;
}
$result['completed_at'] = microtime(true);
echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";

function mysqlWorkerCrossLock(PDO $pdo, array $job, string $other, ?int &$driverCode): void
{
    file_put_contents($job['phase_ready'], 'locked');
    $deadline = microtime(true) + 10;
    while (!is_file($job['phase_start'])) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Deadlock barrier timed out.');
        }
        usleep(5000);
    }
    try {
        $lock = $pdo->prepare('SELECT last_seq FROM repository_locks WHERE name = ? FOR UPDATE');
        $lock->execute([$other]);
    } catch (PDOException $error) {
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
        throw $error;
    }
}

function mysqlWorkerTimeoutProbe(MySQLUserRepository $auth, PDO $app, array $job, string $now, ?int &$driverCode): void
{
    try {
        $auth->transaction(static function (UserTransactionInterface $tx) use ($job, $now): void {
            $tx->update($job['id'], $job['changes']);
            $tx->appendAudit(1, $job['id'], 'user.updated', $job['changes'], $now);
        });
    } catch (RuntimeException $sanitized) {
        // Verify the server error code independently while the parent still holds
        // the row. This occurs after the adapter has rolled back its failed call.
        try {
            $app->beginTransaction();
            $statement = $app->prepare('SELECT last_seq FROM repository_locks WHERE name = ? FOR UPDATE');
            $statement->execute(['auth']);
        } catch (PDOException $error) {
            $driverCode = (int) ($error->errorInfo[1] ?? 0);
        } finally {
            if ($app->inTransaction()) { $app->rollBack(); }
        }
        throw $sanitized;
    }
}

function mysqlWorkerFollowUp(MySQLUserRepository $auth, bool $rollBackFirst, string $now): void
{
    try {
        $auth->transaction(static function (UserTransactionInterface $tx) use ($rollBackFirst, $now): void {
            $tx->update(3, ['email' => 'first-write@example.test']);
            $tx->appendAudit(1, 3, 'user.updated', ['email' => 'first-write@example.test'], $now);
            if ($rollBackFirst) { throw new RuntimeException('Injected failure.'); }
        });
    } catch (RuntimeException $error) {
        if (!$rollBackFirst || $error->getMessage() !== 'Injected failure.') { throw $error; }
    }
    $auth->transaction(static function (UserTransactionInterface $tx) use ($now): void {
        $tx->update(5, ['status' => 'inactive']);
        $tx->appendAudit(1, 5, 'user.updated', ['status' => 'inactive'], $now);
    });
}
