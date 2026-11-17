<?php
declare(strict_types=1);

use App\Auth\Repositories\MockUserRepository;
use App\Auth\Services\AuthorizationService;
use App\Core\JsonStore;
use App\Students\Contracts\FacultyStudentVisibility;
use App\Students\Repositories\JsonProgramCatalog;
use App\Students\Repositories\MockStudentRepository;
use App\Students\Services\StudentNumberPolicy;
use App\Students\Services\StudentService;

require_once dirname(__DIR__) . '/Auth/HttpSupport.php';

/** Fictional accounts (password Synthetic-test-pass-42). */
function studentUserRows(): array
{
    $hash = authTestRows()[0]['password_hash'];
    $row = static fn (int $id, string $email, array $roles, string $status = 'active'): array
        => ['id' => $id, 'email' => $email, 'roles' => $roles, 'status' => $status, 'password_hash' => $hash, 'auth_version' => 1];
    return [
        $row(1, 'admin@example.test', ['admin']),
        $row(2, 'registrar@example.test', ['registrar']),
        $row(3, 'faculty@example.test', ['faculty']),
        $row(4, 'ana@example.test', ['student']),
        $row(5, 'ben@example.test', ['student']),
        $row(6, 'gone@example.test', ['student'], 'inactive'),
        $row(7, 'noprofile@example.test', ['student']),
        $row(8, 'faculty2@example.test', ['faculty']),
    ];
}

function studentPrograms(): array
{
    return [['code' => 'BSCS', 'name' => 'BS Computer Science', 'max_year_level' => 4],
        ['code' => 'ASCT', 'name' => 'Associate in Computer Technology', 'max_year_level' => 2]];
}

function anaInput(array $override = []): array
{
    return array_replace(['user_id' => 4, 'student_number' => '2026-00001', 'first_name' => 'Ana', 'last_name' => 'Reyes',
        'program_code' => 'BSCS', 'year_level' => 2], $override);
}

/** Test-only visibility: faculty user 3 teaches section 12, in which student id 1 is enrolled. */
final class StubFacultyVisibility implements FacultyStudentVisibility
{
    public array $calls = [];

    public function __construct(private readonly mixed $answer = null)
    {
    }

    public function sharedSections(int $facultyUserId, int $studentId): array
    {
        $this->calls[] = [$facultyUserId, $studentId];
        if ($this->answer instanceof Throwable) {
            throw $this->answer;
        }
        if ($this->answer !== null) {
            return $this->answer;
        }
        return $facultyUserId === 3 && $studentId === 1
            ? [['section_id' => 12, 'section_code' => 'CS201-A', 'enrollment_status' => 'approved']] : [];
    }
}

/** Seeds users, programs and (optionally) students into $directory using the runtime layout. */
function seedStudentRuntime(string $directory, bool $withUsers = true): void
{
    if ($withUsers) {
        (new MockUserRepository(new JsonStore($directory . '/auth/users.json')))->seed(studentUserRows());
    }
    (new JsonProgramCatalog(new JsonStore($directory . '/students/programs.json')))->seed(studentPrograms());
}

/** Test-only counts source. */
final class StubSummarySource implements App\Students\Contracts\AcademicSummarySource
{
    public function __construct(private readonly mixed $answer)
    {
    }

    public function countsFor(int $studentId): ?array
    {
        if ($this->answer instanceof Throwable) {
            throw $this->answer;
        }
        return $this->answer;
    }
}

/** @return array{0: StudentService, 1: App\Students\Services\AcademicRecordService, 2: MockStudentRepository, 3: MockUserRepository, 4: App\Students\Services\RepositoryStudentDirectory} */
function academicFixture(string $directory, $enrollment = null, $grades = null): array
{
    [$service, $repository, $users] = studentFixture($directory);
    $records = new App\Students\Services\AcademicRecordService($repository,
        new JsonProgramCatalog(new JsonStore($directory . '/students/programs.json')), new AuthorizationService(),
        $enrollment ?? new App\Students\Contracts\UnavailableSummarySource(), $grades ?? new App\Students\Contracts\UnavailableSummarySource());
    return [$service, $records, $repository, $users, new App\Students\Services\RepositoryStudentDirectory($repository, $users)];
}

/** @return array{0: StudentService, 1: MockStudentRepository, 2: MockUserRepository} */
function studentFixture(string $directory, ?FacultyStudentVisibility $visibility = null, ?string $pattern = null): array
{
    seedStudentRuntime($directory);
    $users = new MockUserRepository(new JsonStore($directory . '/auth/users.json'));
    $repository = new MockStudentRepository(new JsonStore($directory . '/students/students.json'));
    $service = new StudentService($repository, new JsonProgramCatalog(new JsonStore($directory . '/students/programs.json')),
        $users, new AuthorizationService(), $visibility ?? new StubFacultyVisibility(), new StudentNumberPolicy($pattern),
        static fn (): int => 1_780_000_000);
    return [$service, $repository, $users];
}
