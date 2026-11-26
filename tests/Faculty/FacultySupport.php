<?php
declare(strict_types=1);

use App\Auth\Repositories\MockUserRepository;
use App\Auth\Services\AuthorizationService;
use App\Core\JsonStore;
use App\Enrollment\Repositories\MockCatalogRepository;
use App\Enrollment\Services\RepositorySectionDirectory;
use App\Enrollment\Services\SectionService;
use App\Enrollment\Services\TermService;
use App\Faculty\Repositories\MockFacultyRepository;
use App\Faculty\Services\EmployeeNumberPolicy;
use App\Faculty\Services\FacultyService;
use App\Faculty\Services\RepositoryFacultyDirectory;
use App\Faculty\Services\RepositoryFacultyIdentity;

require_once dirname(__DIR__) . '/Enrollment/EnrollmentSupport.php';

/**
 * Fictional accounts (password Synthetic-test-pass-42): admin 1, registrar 2, faculty 3, student 4, inactive faculty 5,
 * faculty 6 and 7 (their user ids differ from their future faculty ids), faculty 8 (never gets a profile in unit tests).
 */
function facultyUserRows(): array
{
    $hash = authTestRows()[0]['password_hash'];
    $row = static fn (int $id, string $email, array $roles, string $status = 'active'): array
        => ['id' => $id, 'email' => $email, 'roles' => $roles, 'status' => $status, 'password_hash' => $hash, 'auth_version' => 1];
    return [
        $row(1, 'admin@example.test', ['admin']),
        $row(2, 'registrar@example.test', ['registrar']),
        $row(3, 'faculty@example.test', ['faculty']),
        $row(4, 'ana@example.test', ['student']),
        $row(5, 'gone@example.test', ['faculty'], 'inactive'),
        $row(6, 'faculty2@example.test', ['faculty']),
        $row(7, 'faculty3@example.test', ['faculty']),
        $row(8, 'faculty4@example.test', ['faculty']),
    ];
}

function facultyInput(array $override = []): array
{
    return array_replace(['user_id' => 3, 'employee_number' => 'EMP-001', 'first_name' => 'Ana', 'last_name' => 'Reyes',
        'department' => 'Computer Science'], $override);
}

function facultyStorePath(string $directory): string
{
    return $directory . '/faculty/faculty.json';
}

function facultyStoreBytes(string $directory): string
{
    return file_get_contents(facultyStorePath($directory));
}

/**
 * @return array{service: FacultyService, repo: MockFacultyRepository, users: MockUserRepository, catalog: MockCatalogRepository,
 *   identity: RepositoryFacultyIdentity, directory: RepositoryFacultyDirectory, sections: SectionService, terms: TermService,
 *   section_directory: RepositorySectionDirectory}
 */
function facultyFixture(string $directory): array
{
    $users = new MockUserRepository(new JsonStore($directory . '/auth/users.json'));
    $users->seed(facultyUserRows());
    $policy = new EmployeeNumberPolicy();
    $repo = new MockFacultyRepository(new JsonStore(facultyStorePath($directory)), $policy);
    $identity = new RepositoryFacultyIdentity($repo, $users);
    $catalog = new MockCatalogRepository(new JsonStore($directory . '/enrollment/enrollment.json'));
    $clock = static fn (): int => 1_780_000_000;
    $sectionDirectory = new RepositorySectionDirectory($catalog, $identity);
    return ['service' => new FacultyService($repo, $users, new AuthorizationService(), $sectionDirectory, $policy, $clock),
        'repo' => $repo, 'users' => $users, 'catalog' => $catalog, 'identity' => $identity,
        'directory' => new RepositoryFacultyDirectory($repo),
        'sections' => new SectionService($catalog, new AuthorizationService(), $identity, $clock),
        'terms' => new TermService($catalog, new AuthorizationService(), $clock),
        'section_directory' => $sectionDirectory];
}

/** Profiles: user 6 -> faculty 1, user 3 -> faculty 2, user 7 -> faculty 3 (ids deliberately differ from user ids). */
function facultyProfiles(array $f): void
{
    $actor = $f['users']->findById(2);
    $f['service']->create($actor, facultyInput(['user_id' => 6, 'employee_number' => 'EMP-006', 'first_name' => 'Ben', 'last_name' => 'Cruz']));
    $f['service']->create($actor, facultyInput());
    $f['service']->create($actor, facultyInput(['user_id' => 7, 'employee_number' => 'EMP-007', 'first_name' => 'Cara', 'last_name' => 'Lim']));
}

function facultyValidation(callable $action): array
{
    $error = httpFailure($action);
    same(422, $error->status);
    return $error->errors;
}

function facultyServer(): AuthHttpServer
{
    return new AuthHttpServer([], 'public/router.php', facultyUserRows());
}

/** Edits one account directly in the Team 2 store (simulates an admin change). */
function changeFacultyAccount(string $directory, int $userId, callable $change): void
{
    (new JsonStore($directory . '/auth/users.json'))->transaction(static function (array &$data) use ($userId, $change): void {
        foreach ($data['users'] as $i => $row) {
            if ($row['id'] === $userId) {
                $data['users'][$i] = $change($row);
                return;
            }
        }
        throw new RuntimeException('Missing account.');
    });
}
