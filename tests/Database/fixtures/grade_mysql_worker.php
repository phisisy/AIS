<?php
declare(strict_types=1);

// UNVERIFIED (never executed). CLI worker for T-32 MySQL concurrency tests, on the app account against ais_test.
// Usage: php grade_mysql_worker.php <start-flag> <ready-flag> <op: finalize|approve|correct> <json-input>
require dirname(__DIR__, 3) . '/app/autoload.php';
require dirname(__DIR__) . '/MySQLTestSupport.php';

use App\Core\HttpException;
use App\Enrollment\Repositories\MySQLCatalogRepository;
use App\Faculty\Repositories\MySQLGradeRepository;

if (PHP_SAPI !== 'cli' || $argc !== 5 || !in_array($argv[3], ['finalize', 'approve', 'correct'], true)) {
    exit(2);
}
$app = null;
try {
    $input = json_decode($argv[4], true, 512, JSON_THROW_ON_ERROR);
    $app = MySQLTestSupport::connectApp();
    $app->exec('SET SESSION innodb_lock_wait_timeout = 10');
    $catalog = new MySQLCatalogRepository($app);
    $grades = new MySQLGradeRepository($app, $catalog);
    $now = '2025-01-03T00:00:00Z';
    file_put_contents($argv[2], 'ready');
    $deadline = microtime(true) + 10;
    while (!is_file($argv[1])) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Start barrier timed out.');
        }
        usleep(1000);
    }
    match ($argv[3]) {
        'finalize' => $grades->finalizeSection($input['section_id'], $now, $input['actor_id']),
        'approve' => $catalog->decideEnrollment($input['enrollment_id'], 'approved', null, $now, $input['actor_id']),
        'correct' => $grades->correct($input['grade_id'], $input['value'], $input['reason'], $input['expected_seq'], $now, $input['actor_id']),
    };
    $result = ['ok' => true, 'status' => 200, 'errors' => new stdClass()];
} catch (HttpException $error) {
    $result = ['ok' => false, 'status' => $error->status, 'errors' => $error->errors === [] ? new stdClass() : $error->errors];
} catch (Throwable $error) {
    $result = ['ok' => false, 'status' => 500, 'errors' => new stdClass(), 'message' => $error->getMessage()];
} finally {
    if ($app instanceof PDO && $app->inTransaction()) {
        try { $app->rollBack(); } catch (Throwable) { /* connection cleanup */ }
    }
}
echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
