<?php
declare(strict_types=1);

// UNVERIFIED: written without being executed (compromised host, owner forbids running tests).

use App\Auth\Services\AuthorizationService;
use App\Core\JsonStore;
use App\Enrollment\Repositories\MockCatalogRepository;
use App\Enrollment\Services\RepositorySectionDirectory;
use App\Faculty\Repositories\MockGradeRepository;
use App\Faculty\Services\GradeScale;
use App\Faculty\Services\GradeService;
use App\Faculty\Services\RepositoryGradeReader;

require_once dirname(__DIR__) . '/Enrollment/EnrollmentSupport.php';
require_once __DIR__ . '/fixtures/StubFacultyDirectory.php';

/** Test-only scale double (plan 4.2): never selectable by config or env. */
function gradeTestScale(): GradeScale
{
    return new GradeScale(['1.00', '1.25', '2.00', '5.00', 'INC']);
}

function gradeStoreBytes(string $directory): string
{
    return file_get_contents($directory . '/faculty/grades.json');
}

function gradeHistory(string $directory): array
{
    return json_decode(gradeStoreBytes($directory), true)['history'] ?? [];
}

/**
 * Section 1 (CS101-A) of the T-22 fixture, instructor faculty 10 (user 3, active), students 1/2/3 = ana/ben/cara.
 * $approve lists the student ids approved up front; the rest stay without an enrollment. Registrar user 2 has no profile.
 * @return array{service: GradeService, repo: MockGradeRepository, catalog: MockCatalogRepository, enroll: object, users: object,
 *   faculty: object, ana: object, ben: object, cara: object, registrar: object, admin: object, student: array, directory: StubFacultyDirectory,
 *   enrollments: array<int,int>, reader: RepositoryGradeReader}
 */
function gradeFixture(string $dir, ?GradeScale $scale = null, array $approve = [1, 2, 3]): array
{
    [$enroll, $catalog, $users, , , $students] = requestFixture($dir);
    [$ana, $ben, $cara, $registrar, $admin, $faculty] = t22Actors($users);
    $byId = [1 => $ana, 2 => $ben, 3 => $cara];
    $enrollments = [];
    foreach ($approve as $studentId) {
        $enrollments[$studentId] = $enroll->request($byId[$studentId], ['section_id' => 1])['id'];
        $enroll->decide($registrar, $enrollments[$studentId], ['status' => 'approved']);
    }
    seedAssignment($dir, 1, 10);
    $identity = new StubFacultyIdentity([10 => ['name' => 'Instructor', 'active' => true]]);
    $directory = new StubFacultyDirectory();
    $repo = new MockGradeRepository(new JsonStore($dir . '/faculty/grades.json'), $catalog);
    $reader = new RepositoryGradeReader($repo, $catalog);
    $service = new GradeService($repo, $scale ?? gradeTestScale(), new RepositorySectionDirectory($catalog, $identity),
        $directory, $students, $reader, new AuthorizationService(), static fn (): int => 1_780_000_000);
    return ['service' => $service, 'repo' => $repo, 'catalog' => $catalog, 'enroll' => $enroll, 'users' => $users,
        'faculty' => $faculty, 'ana' => $ana, 'ben' => $ben, 'cara' => $cara, 'registrar' => $registrar, 'admin' => $admin,
        'directory' => $directory, 'enrollments' => $enrollments, 'reader' => $reader, 'student' => $byId, 'students' => $students];
}

/** Faculty enters a draft for every listed student, then submits the section. */
function gradeSubmitAll(array $f, array $studentIds = [1, 2, 3], string $value = '1.25'): void
{
    foreach ($studentIds as $id) {
        $f['service']->enter($f['faculty'], 1, $id, ['value' => $value, 'remarks' => 'staff only note']);
    }
    $f['service']->submit($f['faculty'], 1);
}

function gradeFailure(callable $action, int $status, ?string $key = null): array
{
    $error = httpFailure($action);
    same($status, $error->status);
    if ($key !== null) {
        check(array_key_exists($key, $error->errors), 'Expected error key ' . $key);
    }
    return $error->errors;
}
