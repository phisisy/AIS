<?php
declare(strict_types=1);

// UNVERIFIED (never executed). CLI worker for T-32 concurrency tests against the mock stores.
// Usage: php grade_worker.php <runtime-dir> <start-flag> <ready-flag> <op: finalize|approve|correct|return> <json-input> <id> [actor-user-id]
require dirname(__DIR__, 3) . '/app/autoload.php';
require dirname(__DIR__, 2) . '/Enrollment/fixtures/StubFacultyIdentity.php';
require dirname(__DIR__, 2) . '/Enrollment/fixtures/StubStudentDirectory.php';
require __DIR__ . '/StubFacultyDirectory.php';

use App\Auth\Repositories\MockUserRepository;
use App\Auth\Services\AuthorizationService;
use App\Core\HttpException;
use App\Core\JsonStore;
use App\Enrollment\Repositories\MockCatalogRepository;
use App\Enrollment\Services\EnrollmentService;
use App\Enrollment\Services\RepositorySectionDirectory;
use App\Enrollment\Services\SectionService;
use App\Faculty\Repositories\MockGradeRepository;
use App\Faculty\Services\GradeScale;
use App\Faculty\Services\GradeService;
use App\Faculty\Services\RepositoryGradeReader;

if (PHP_SAPI !== 'cli' || $argc < 7 || !in_array($argv[4], ['finalize', 'approve', 'correct', 'return'], true)) {
    exit(2);
}
[$dir, $start, $ready, $op, $json, $id] = array_slice($argv, 1, 6);
$users = new MockUserRepository(new JsonStore($dir . '/auth/users.json'));
$catalog = new MockCatalogRepository(new JsonStore($dir . '/enrollment/enrollment.json'));
$authorization = new AuthorizationService();
$identity = new StubFacultyIdentity([10 => ['name' => 'Instructor', 'active' => true]]);
$students = new StubStudentDirectory();
$sectionDirectory = new RepositorySectionDirectory($catalog, $identity);
$grades = new MockGradeRepository(new JsonStore($dir . '/faculty/grades.json'), $catalog);
$service = new GradeService($grades, new GradeScale(['1.00', '1.25', '2.00', '5.00', 'INC']), $sectionDirectory,
    new StubFacultyDirectory(), $students, new RepositoryGradeReader($grades, $catalog), $authorization);
$enrollments = new EnrollmentService($catalog, $students, new SectionService($catalog, $authorization, $identity), $authorization);
$actor = $users->findById((int) ($argv[7] ?? 2));
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
        'finalize' => $service->finalize($actor, (int) $id),
        'approve' => $enrollments->decide($actor, (int) $id, $input),
        'correct' => $service->correct($actor, (int) $id, $input),
        'return' => $service->returnToDraft($actor, (int) $id, $input),
    };
    echo json_encode(['ok' => true, 'status' => 200, 'errors' => new stdClass()]);
} catch (HttpException $error) {
    echo json_encode(['ok' => false, 'status' => $error->status, 'errors' => $error->errors === [] ? new stdClass() : $error->errors]);
}
