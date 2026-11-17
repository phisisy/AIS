<?php
declare(strict_types=1);

use App\Auth\Repositories\MockUserRepository;
use App\Auth\Services\AuthorizationService;
use App\Core\HttpException;
use App\Core\JsonStore;
use App\Enrollment\Repositories\MockCatalogRepository;
use App\Enrollment\Services\SectionService;
use App\Enrollment\Services\TermService;

require_once dirname(__DIR__) . '/Auth/HttpSupport.php';

/** Fictional accounts (password Synthetic-test-pass-42): admin 1, registrar 2, faculty 3, student 4, inactive 5. */
function enrollmentUserRows(): array
{
    $hash = authTestRows()[0]['password_hash'];
    $row = static fn (int $id, string $email, array $roles, string $status = 'active'): array
        => ['id' => $id, 'email' => $email, 'roles' => $roles, 'status' => $status, 'password_hash' => $hash, 'auth_version' => 1];
    return [
        $row(1, 'admin@example.test', ['admin']),
        $row(2, 'registrar@example.test', ['registrar']),
        $row(3, 'faculty@example.test', ['faculty']),
        $row(4, 'ana@example.test', ['student']),
        $row(5, 'gone@example.test', ['student'], 'inactive'),
    ];
}

/** @return array{0: TermService, 1: SectionService, 2: MockCatalogRepository, 3: MockUserRepository} */
function enrollmentFixture(string $directory): array
{
    $users = new MockUserRepository(new JsonStore($directory . '/auth/users.json'));
    $users->seed(enrollmentUserRows());
    $repository = new MockCatalogRepository(new JsonStore($directory . '/enrollment/enrollment.json'));
    $clock = static fn (): int => 1_780_000_000;
    return [new TermService($repository, new AuthorizationService(), $clock),
        new SectionService($repository, new AuthorizationService(), $clock), $repository, $users];
}

function termInput(array $override = []): array
{
    return array_replace(['code' => '2026-1S', 'name' => 'First Semester 2026-2027',
        'start_date' => '2026-08-10', 'end_date' => '2026-12-18'], $override);
}

/** A meeting as Request delivers it (stdClass). */
function meeting(string $day = 'MON', string $start = '09:00', string $end = '10:30', string $room = 'R-101'): stdClass
{
    return (object) ['day' => $day, 'start' => $start, 'end' => $end, 'room' => $room];
}

function sectionInput(array $override = []): array
{
    return array_replace(['term_id' => 1, 'course_code' => 'CS101', 'course_title' => 'Introduction to Computing',
        'units' => 3, 'section_code' => 'A', 'capacity' => 40, 'meetings' => [meeting()]], $override);
}

/** Runs $action and returns the HttpException it must throw. */
function httpFailure(callable $action): HttpException
{
    return throws(HttpException::class, $action);
}

/** Test-only store edit that simulates T-22 changing approved_count. */
function bumpApprovedCount(string $directory, int $sectionId, int $n): void
{
    (new JsonStore($directory . '/enrollment/enrollment.json'))->transaction(static function (array &$data) use ($sectionId, $n): void {
        foreach ($data['sections'] as $i => $row) {
            if ($row['id'] === $sectionId) {
                $data['sections'][$i]['approved_count'] = $n;
                return;
            }
        }
        throw new RuntimeException('Missing section.');
    });
}

/** Writes a raw store snapshot (bypasses the repository) for corruption tests. */
function writeEnrollmentStore(string $directory, string $contents): string
{
    if (!is_dir($directory . '/enrollment')) {
        mkdir($directory . '/enrollment', 0700, true);
    }
    file_put_contents($directory . '/enrollment/enrollment.json', $contents);
    return $directory . '/enrollment/enrollment.json';
}

function enrollmentServer(): AuthHttpServer
{
    return new AuthHttpServer([], 'public/router.php', enrollmentUserRows());
}

/** Signs in as $email (or anonymous) and returns the CSRF token. */
function enrollmentLogin(AuthHttpServer $server, ?string $email): ?string
{
    $server->setCookie('');
    return $email === null ? null : $server->login($email)['json']['data']['csrf_token'];
}
