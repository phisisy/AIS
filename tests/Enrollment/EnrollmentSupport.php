<?php
declare(strict_types=1);

use App\Auth\Repositories\MockUserRepository;
use App\Auth\Services\AuthorizationService;
use App\Core\HttpException;
use App\Core\JsonStore;
use App\Enrollment\Repositories\MockCatalogRepository;
use App\Enrollment\Services\EnrollmentService;
use App\Enrollment\Services\SectionService;
use App\Enrollment\Services\TermService;
use App\Enrollment\Contracts\FacultyIdentityLookup;

require_once __DIR__ . '/fixtures/StubFacultyIdentity.php';
require_once __DIR__ . '/fixtures/StubStudentDirectory.php';

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
        $row(6, 'ben@example.test', ['student']),
        $row(7, 'cara@example.test', ['student']),
    ];
}

/**
 * T-22 service fixture: open current term 1; section 1 CS101-A MON 09:00-10:30 (cap 40), section 2 CS102-A MON 10:00-11:00 (cap 1,
 * clashes with 1), section 3 CS103-A TUE 09:00-10:00, section 4 CS104-A tba with no meetings. Students 1/2/3 are users 4/6/7.
 * @return array{0: EnrollmentService, 1: MockCatalogRepository, 2: MockUserRepository, 3: SectionService, 4: TermService, 5: StubStudentDirectory}
 */
function requestFixture(string $directory, bool $seed = true): array
{
    [$terms, $sections, $repository, $users] = enrollmentFixture($directory);
    $directoryStub = new StubStudentDirectory();
    $service = new EnrollmentService($repository, $directoryStub, $sections, new AuthorizationService(), static fn (): int => 1_780_000_000);
    if ($seed) {
        $registrar = $users->findById(2);
        $terms->create($registrar, termInput(['is_current' => true, 'enrollment_open' => true]));
        $sections->create($registrar, sectionInput());
        $sections->create($registrar, sectionInput(['course_code' => 'CS102', 'capacity' => 1, 'meetings' => [meeting('MON', '10:00', '11:00')]]));
        $sections->create($registrar, sectionInput(['course_code' => 'CS103', 'meetings' => [meeting('TUE', '09:00', '10:00')]]));
        $sections->create($registrar, sectionInput(['course_code' => 'CS104', 'delivery_mode' => 'tba', 'meetings' => []]));
    }
    return [$service, $repository, $users, $sections, $terms, $directoryStub];
}

/** @return array{0: object, 1: object, 2: object, 3: object, 4: object, 5: object} ana, ben, cara (students 1-3), registrar, admin, faculty */
function t22Actors(MockUserRepository $users): array
{
    return array_map(static fn (int $id) => $users->findById($id), [4, 6, 7, 2, 1, 3]);
}

function t22Now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z', 1_780_000_000);
}

function t22History_(string $directory): array
{
    return json_decode(storeBytes($directory), true)['enrollment_history'] ?? [];
}

/** Valid raw store rows for corruption tests (one pending enrollment 1 of student 1 in section 1, with its history). */
function t22Store(array $override = []): array
{
    $stamp = '2026-11-17T00:00:00Z';
    return array_replace(['terms' => [['id' => 1, 'code' => '2026-1S', 'name' => 'First', 'start_date' => '2026-08-10', 'end_date' => '2026-12-18',
            'is_current' => true, 'enrollment_open' => true, 'created_at' => $stamp, 'updated_at' => $stamp]],
        'sections' => [['id' => 1, 'term_id' => 1, 'course_code' => 'CS101', 'course_title' => 'Intro', 'units' => 3, 'section_code' => 'A',
            'capacity' => 40, 'approved_count' => 0, 'delivery_mode' => 'scheduled',
            'meetings' => [['day' => 'MON', 'start' => '09:00', 'end' => '10:30', 'room' => 'R-101']], 'created_at' => $stamp, 'updated_at' => $stamp]],
        'enrollments' => [t22Enrollment()],
        'enrollment_history' => [t22History()]], $override);
}

function t22Enrollment(array $override = []): array
{
    return array_replace(['id' => 1, 'student_id' => 1, 'section_id' => 1, 'status' => 'pending', 'requested_at' => '2026-11-18T00:00:00Z',
        'decided_at' => null, 'decided_by' => null, 'dropped_at' => null, 'reason' => null], $override);
}

function t22History(array $override = []): array
{
    return array_replace(['enrollment_id' => 1, 'seq' => 1, 'at' => '2026-11-18T00:00:00Z', 'action' => 'requested', 'actor_id' => 4, 'reason' => null], $override);
}

/** @return array{0: TermService, 1: SectionService, 2: MockCatalogRepository, 3: MockUserRepository} */
function enrollmentFixture(string $directory, ?FacultyIdentityLookup $identities = null): array
{
    $users = new MockUserRepository(new JsonStore($directory . '/auth/users.json'));
    $users->seed(enrollmentUserRows());
    $repository = new MockCatalogRepository(new JsonStore($directory . '/enrollment/enrollment.json'));
    $clock = static fn (): int => 1_780_000_000;
    return [new TermService($repository, new AuthorizationService(), $clock),
        new SectionService($repository, new AuthorizationService(), $identities, $clock), $repository, $users];
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

function assignInput(int $facultyId = 10): array
{
    return ['faculty_id' => $facultyId];
}

function storeBytes(string $directory): string
{
    return file_get_contents($directory . '/enrollment/enrollment.json');
}

/** Raw, consistent fixture for production HTTP reads and DELETE. */
function seedAssignment(string $directory, int $sectionId, int $facultyId): void
{
    (new JsonStore($directory . '/enrollment/enrollment.json'))->transaction(static function (array &$data) use ($sectionId, $facultyId): void {
        $data['assignments'] ??= [];
        $data['assignment_history'] ??= [];
        $data['assignments'][] = ['section_id' => $sectionId, 'faculty_id' => $facultyId];
        $data['assignment_history'][] = ['seq' => count($data['assignment_history']) + 1, 'at' => '2026-11-20T00:00:00Z',
            'action' => 'assigned', 'section_id' => $sectionId, 'faculty_id' => $facultyId, 'actor_id' => 2];
    });
}

/** True when the HTTP MySQL switch runs enrollment and faculty on SQL (C3/C4); the JSON stores are then gone after import. */
function enrollmentOnMysql(): bool
{
    return in_array(getenv('AIS_HTTP_MYSQL'), ['C3', 'C4'], true);
}

/** Tester connection for seeding only. Valid after the server's first request (the switch resets and imports lazily on it). */
function enrollmentTesterPdo(): PDO
{
    require_once dirname(__DIR__) . '/Database/MySQLTestSupport.php';
    $pdo = MySQLTestSupport::connect();
    check(str_starts_with((string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn(), 'ais_tester@'), 'Seeding requires the tester account.');
    check((int) $pdo->query('SELECT COUNT(*) FROM terms')->fetchColumn() >= 1, 'Seed only after the first request (runtime not prepared).');
    return $pdo;
}

/**
 * Faculty profile 10 ("Reyes, Ana", user 3, active) in the store the server reads: faculty.json in mock mode, tester-side rows
 * (with the two gap-free history rows and the faculty lock) on C3/C4. Call after the first request and before assertions.
 */
function seedFacultyProfile(AuthHttpServer $server): void
{
    if (!enrollmentOnMysql()) {
        (new JsonStore($server->directory . '/faculty/faculty.json'))->transaction(static function (array &$data): void {
            $at = '2026-11-19T00:00:00Z';
            $data = ['faculty' => [['id' => 10, 'user_id' => 3, 'employee_number' => 'EMP-010', 'first_name' => 'Ana', 'last_name' => 'Reyes',
                    'department' => 'Computer Science', 'status' => 'active', 'created_at' => $at, 'updated_at' => $at]],
                'history' => [['seq' => 1, 'at' => $at, 'faculty_id' => 10, 'field' => 'status', 'from' => null, 'to' => 'active', 'actor_id' => 2],
                    ['seq' => 2, 'at' => $at, 'faculty_id' => 10, 'field' => 'department', 'from' => null, 'to' => 'Computer Science', 'actor_id' => 2]]];
        });
        return;
    }
    $pdo = enrollmentTesterPdo();
    $pdo->beginTransaction();
    try {
        $pdo->exec("INSERT INTO faculty (id, user_id, employee_number, first_name, last_name, department, status, created_at, updated_at)
            VALUES (10, 3, 'EMP-010', 'Ana', 'Reyes', 'Computer Science', 'active', '2026-11-19 00:00:00', '2026-11-19 00:00:00')");
        $pdo->exec("INSERT INTO faculty_history (seq, at, faculty_id, field, from_value, to_value, actor_id) VALUES
            (1, '2026-11-19 00:00:00', 10, 'status', NULL, 'active', 2), (2, '2026-11-19 00:00:00', 10, 'department', NULL, 'Computer Science', 2)");
        check($pdo->exec("UPDATE repository_locks SET last_seq = 2 WHERE name = 'faculty' AND last_seq = 0") === 1, 'Faculty lock was not fresh.');
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

/** seedAssignment() for the store the server reads (mock JSON, or tester-side rows on C3/C4; the faculty row must exist there). */
function seedServerAssignment(AuthHttpServer $server, int $sectionId, int $facultyId): void
{
    if (!enrollmentOnMysql()) {
        seedAssignment($server->directory, $sectionId, $facultyId);
        return;
    }
    $pdo = enrollmentTesterPdo();
    $pdo->beginTransaction();
    try {
        $seq = (int) $pdo->query("SELECT last_seq FROM repository_locks WHERE name = 'enrollment' FOR UPDATE")->fetchColumn() + 1;
        $pdo->prepare("INSERT INTO section_instructor_history (seq, at, action, section_id, faculty_id, actor_id) VALUES (?, '2026-11-20 00:00:00', 'assigned', ?, ?, 2)")
            ->execute([$seq, $sectionId, $facultyId]);
        $pdo->prepare('INSERT INTO section_instructors (section_id, faculty_id, assigned_seq) VALUES (?, ?, ?)')->execute([$sectionId, $facultyId, $seq]);
        $pdo->prepare("UPDATE repository_locks SET last_seq = ? WHERE name = 'enrollment'")->execute([$seq]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

/** Instructor-assignment history actions in order, from whichever store the server wrote. */
function serverAssignmentActions(AuthHttpServer $server): array
{
    if (!enrollmentOnMysql()) {
        return array_column(json_decode(storeBytes($server->directory), true)['assignment_history'], 'action');
    }
    return enrollmentTesterPdo()->query('SELECT action FROM section_instructor_history ORDER BY seq')->fetchAll(PDO::FETCH_COLUMN);
}

/** Enrollment history actions grouped by enrollment id (SQL has per-enrollment seq, no global order), from either store. */
function serverEnrollmentActionsById(AuthHttpServer $server): array
{
    $grouped = [];
    if (!enrollmentOnMysql()) {
        foreach (json_decode(storeBytes($server->directory), true)['enrollment_history'] as $row) {
            $grouped[$row['enrollment_id']][] = $row['action'];
        }
    } else {
        foreach (enrollmentTesterPdo()->query('SELECT enrollment_id, action FROM enrollment_history ORDER BY enrollment_id, seq')->fetchAll(PDO::FETCH_NUM) as [$id, $action]) {
            $grouped[(int) $id][] = $action;
        }
    }
    ksort($grouped);
    return $grouped;
}

/** Every table the enrollment module owns (the SQL counterpart of the whole enrollment.json store). */
const ENROLLMENT_SQL_TABLES = ['terms', 'sections', 'section_meetings', 'enrollments', 'enrollment_history',
    'section_instructors', 'section_instructor_history'];

/**
 * "Store unchanged" evidence from whichever store the server reads: the exact file bytes in mock mode (as before), and on
 * C3/C4 (no file exists) an order-independent row-level hash of all enrollment tables plus the enrollment lock row.
 */
function serverEnrollmentSnapshot(AuthHttpServer $server): string
{
    return enrollmentOnMysql() ? httpTableSnapshot(ENROLLMENT_SQL_TABLES, 'enrollment') : storeBytes($server->directory);
}

/** Enrollment statuses in id order, from either store (mock: the 'enrollments' list, empty when absent). */
function serverEnrollmentStatuses(AuthHttpServer $server): array
{
    if (!enrollmentOnMysql()) {
        return array_column(json_decode(storeBytes($server->directory), true)['enrollments'] ?? [], 'status');
    }
    return httpTesterPdo()->query('SELECT status FROM enrollments ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
}

/** bumpApprovedCount() for the store the server reads (SQL: capacity must stay >= the count, as its CHECK demands). */
function serverBumpApprovedCount(AuthHttpServer $server, int $sectionId, int $n): void
{
    if (!enrollmentOnMysql()) {
        bumpApprovedCount($server->directory, $sectionId, $n);
        return;
    }
    $changed = httpTesterPdo()->prepare('UPDATE sections SET approved_count = ? WHERE id = ?');
    $changed->execute([$n, $sectionId]);
    check($changed->rowCount() === 1, 'Missing section.');
}

/**
 * MySQL only (C3/C4): rows the schema accepts but the models refuse, so the catalog fails closed on every route (V9 style).
 * The mock's "approved_count above capacity" is refused by ck_sections_capacity, which is asserted here instead of injected.
 *  - terms: a zero start_date (Term::validDate / checkdate refuses it), written with a lenient session;
 *  - sections: a meeting row whose position is not contiguous (loadSections refuses it).
 */
function serverCorruptCatalogRows(): void
{
    $pdo = httpTesterPdo();
    $refused = false;
    try {
        $pdo->exec('UPDATE sections SET approved_count = capacity + 1 WHERE id = 1');
    } catch (PDOException) {
        $refused = true;
    }
    check($refused, 'ck_sections_capacity must refuse approved_count above capacity.');
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("UPDATE terms SET start_date = '0000-00-00' WHERE id = 1");
    check($pdo->exec('UPDATE section_meetings SET position = 1 WHERE section_id = 1 AND position = 0') === 1, 'Meeting row missing.');
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
