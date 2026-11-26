<?php
declare(strict_types=1);

// CLI worker for Team 5 concurrency tests. Acts as the registrar (user 2); prints {"ok":bool,"status":int,"errors":{}}.
// Usage: php faculty_worker.php <runtime-dir> <start-flag> <ready-flag> <op> <json-input> [id]
require dirname(__DIR__, 3) . '/app/autoload.php';

use App\Auth\Repositories\MockUserRepository;
use App\Auth\Services\AuthorizationService;
use App\Core\HttpException;
use App\Core\JsonStore;
use App\Enrollment\Repositories\MockCatalogRepository;
use App\Enrollment\Services\SectionService;
use App\Faculty\Repositories\MockFacultyRepository;
use App\Faculty\Services\EmployeeNumberPolicy;
use App\Faculty\Services\FacultyService;
use App\Faculty\Services\RepositoryFacultyDirectory;
use App\Faculty\Services\RepositoryFacultyIdentity;
use App\Enrollment\Services\RepositorySectionDirectory;

if (PHP_SAPI !== 'cli' || $argc < 6 || !in_array($argv[4], ['create', 'update', 'assign'], true)) {
    exit(2);
}
[$dir, $start, $ready, $op, $json] = array_slice($argv, 1, 5);
$users = new MockUserRepository(new JsonStore($dir . '/auth/users.json'));
$policy = new EmployeeNumberPolicy();
$repo = new MockFacultyRepository(new JsonStore($dir . '/faculty/faculty.json'), $policy);
$identity = new RepositoryFacultyIdentity($repo, $users);
$catalog = new MockCatalogRepository(new JsonStore($dir . '/enrollment/enrollment.json'));
$authorization = new AuthorizationService();
$faculty = new FacultyService($repo, $users, $authorization, new RepositorySectionDirectory($catalog, $identity), $policy);
$sections = new SectionService($catalog, $authorization, $identity);
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
    match ($op) {
        'create' => $faculty->create($actor, $input),
        'update' => $faculty->update($actor, (int) $argv[6], $input),
        'assign' => $sections->assignInstructor($actor, (int) $argv[6], $input),
    };
    echo json_encode(['ok' => true, 'status' => 200, 'errors' => new stdClass()]);
} catch (HttpException $error) {
    echo json_encode(['ok' => false, 'status' => $error->status, 'errors' => $error->errors === [] ? new stdClass() : $error->errors]);
}
