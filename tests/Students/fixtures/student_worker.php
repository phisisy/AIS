<?php
declare(strict_types=1);

// CLI worker for Team 3 concurrency tests. Acts as the registrar (user 2).
// Usage: php student_worker.php <runtime-dir> <start-flag> <ready-flag> <create|update> <json-input> [studentId]
require dirname(__DIR__, 3) . '/app/autoload.php';

use App\Auth\Repositories\MockUserRepository;
use App\Auth\Services\AuthorizationService;
use App\Core\HttpException;
use App\Core\JsonStore;
use App\Students\Contracts\DenyAllFacultyVisibility;
use App\Students\Repositories\JsonProgramCatalog;
use App\Students\Repositories\MockStudentRepository;
use App\Students\Services\StudentNumberPolicy;
use App\Students\Services\StudentService;

if (PHP_SAPI !== 'cli' || $argc < 6 || !in_array($argv[4], ['create', 'update'], true)) {
    exit(2);
}
[$dir, $start, $ready, $op, $json] = array_slice($argv, 1, 5);
$users = new MockUserRepository(new JsonStore($dir . '/auth/users.json'));
$service = new StudentService(new MockStudentRepository(new JsonStore($dir . '/students/students.json')),
    new JsonProgramCatalog(new JsonStore($dir . '/students/programs.json')), $users, new AuthorizationService(),
    new DenyAllFacultyVisibility(), new StudentNumberPolicy());
$actor = $users->findById(2);
$input = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
file_put_contents($ready, 'ready');
$deadline = microtime(true) + 10;
while (!is_file($start)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Start barrier timed out\n");
        exit(1);
    }
    usleep(1000);
}
try {
    $op === 'create' ? $service->create($actor, $input) : $service->update($actor, (int) $argv[6], $input);
    echo json_encode(['ok' => true, 'status' => 200]);
} catch (HttpException $exception) {
    echo json_encode(['ok' => false, 'status' => $exception->status, 'errors' => $exception->errors]);
}
