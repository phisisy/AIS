<?php
declare(strict_types=1);

use App\Auth\Controllers\AuthController;
use App\Auth\Controllers\UserController;
use App\Auth\Services\UserService;
use App\Auth\Middleware\RouteGuard;
use App\Auth\Services\AuthorizationService;
use App\Auth\Services\SecurityEventLog;
use App\Auth\Repositories\MockUserRepository;
use App\Auth\Repositories\MySQLUserRepository;
use App\Auth\Services\AuthService;
use App\Auth\Services\LoginRateLimiter;
use App\Auth\Session;
use App\Core\Database;
use App\Core\Environment;
use App\Core\JsonStore;
use App\Core\Response;
use App\Core\Router;
use App\Core\RuntimeDirectory;
use App\Enrollment\Controllers\TermController;
use App\Enrollment\Controllers\SectionController;
use App\Enrollment\Controllers\EnrollmentController;
use App\Enrollment\Services\EnrollmentService;
use App\Enrollment\Services\EnrollmentSummarySource;
use App\Enrollment\Repositories\MockCatalogRepository;
use App\Enrollment\Repositories\MySQLCatalogRepository;
use App\Enrollment\Services\TermService;
use App\Enrollment\Services\SectionService;
use App\Enrollment\Services\RepositorySectionDirectory;
use App\Faculty\Controllers\FacultyController;
use App\Faculty\Controllers\GradeController;
use App\Faculty\Repositories\MockGradeRepository;
use App\Faculty\Repositories\MySQLGradeRepository;
use App\Faculty\Services\GradeScale;
use App\Faculty\Services\GradeService;
use App\Faculty\Services\RepositoryGradeReader;
use App\Faculty\Repositories\MockFacultyRepository;
use App\Faculty\Repositories\MySQLFacultyRepository;
use App\Faculty\Services\EmployeeNumberPolicy;
use App\Faculty\Services\FacultyService;
use App\Faculty\Services\RepositoryFacultyDirectory;
use App\Faculty\Services\RepositoryFacultyIdentity;
use App\Students\Controllers\AcademicRecordController;
use App\Students\Services\AcademicRecordService;
use App\Students\Services\RepositoryStudentDirectory;
use App\Students\Controllers\StudentController;
use App\Students\Repositories\JsonProgramCatalog;
use App\Students\Repositories\MockStudentRepository;
use App\Students\Repositories\MySQLProgramCatalog;
use App\Students\Repositories\MySQLStudentRepository;
use App\Students\Services\StudentNumberPolicy;
use App\Students\Services\StudentService;

require_once __DIR__ . '/autoload.php';

Environment::load(dirname(__DIR__) . '/.env');
// Explicit container: later approved tasks add services and route wiring here.
$container = [
    'config' => require dirname(__DIR__) . '/config/app.php',
    'database_config' => require dirname(__DIR__) . '/config/database.php',
    'router' => new Router(),
];
$container['router']->add('GET', '/', static fn (): Response => Response::success([], 'AIS API is running.'));

// T-60 Phase 7.1: per-module repository selection. JSON (mock) is the default. MySQL never falls back
// to mock: any failure is a generic 500. One shared PDO per request, opened only when a module is on MySQL.
$repositories = $container['config']['repositories'];
$pdo = null;
if (in_array('mysql', $repositories, true)) {
    $db = $container['database_config'];
    // shortcut: test-only gate (owner OQ-F; no live activation) until the G4 cutover runbook replaces it.
    // The gate runs before the runtime directory is touched.
    if ($container['config']['environment'] !== 'testing'
        || $db['database'] !== 'ais_test' || $db['username'] !== 'ais_test_app'
        || $db['host'] !== '127.0.0.1') { // grants exist only for 'ais_test_app'@'127.0.0.1'
        throw new RuntimeException('MySQL repositories are not available yet.');
    }
    $pdo = Database::connect($db);
    $live = $pdo->query('SELECT DATABASE() AS db_name, CURRENT_USER() AS account')->fetch();
    if (!is_array($live) || $live['db_name'] !== 'ais_test' || $live['account'] !== 'ais_test_app@127.0.0.1') {
        throw new RuntimeException('MySQL repositories are not available yet.');
    }
}
$runtime = RuntimeDirectory::prepare($container['config']['runtime_path'], dirname(__DIR__) . '/public');
$container['session'] = new Session(
    $runtime,
    $container['config']['https'] || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
);
$container['users'] = $repositories['auth'] === 'mysql'
    ? new MySQLUserRepository($pdo)
    : new MockUserRepository(new JsonStore($runtime . '/auth/users.json'));
$limiter = new LoginRateLimiter(new JsonStore($runtime . '/auth/login-attempts.json'));
$container['auth'] = new AuthService($container['users'], $container['session'], $limiter);
// Team 2 — RBAC (T-02). Modules protect routes with $container['guard']->roles([...], $handler).
$container['authorization'] = new AuthorizationService();
// T-04: refused access to admin routes is recorded here (separate from the admin audit log).
$eventsPath = $runtime . '/auth/security-events.json';
$container['security_events'] = new SecurityEventLog(new JsonStore($eventsPath), $eventsPath);
$container['guard'] = new RouteGuard($container['auth'], $container['authorization'], $container['session'],
    $container['security_events']);
// REMOTE_ADDR only: forwarded headers are client-controlled and ignored.
$clientIp = static fn (): string => (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$container['auth_controller'] = new AuthController($container['auth'], $container['session'], $clientIp);
// Team 2 — user administration + audit (T-03).
$container['user_controller'] = new UserController(
    // Separate store: admin password re-confirmation never shares buckets with login.
    new UserService($container['users'], $container['authorization'],
        new LoginRateLimiter(new JsonStore($runtime . '/auth/reauth-attempts.json'))),
    $clientIp
);
// Team 3 — student records (T-10). Faculty visibility is bound below (B-07) once the section directory exists. An invalid STUDENT_NUMBER_PATTERN fails closed here.
// Programs and students switch together (programs is the students' FK parent).
$container['programs'] = $repositories['students'] === 'mysql'
    ? new MySQLProgramCatalog($pdo)
    : new JsonProgramCatalog(new JsonStore($runtime . '/students/programs.json'));
$container['students'] = $repositories['students'] === 'mysql'
    ? new MySQLStudentRepository($pdo)
    : new MockStudentRepository(new JsonStore($runtime . '/students/students.json'));
$container['student_directory'] = new RepositoryStudentDirectory($container['students'], $container['users']);
// Team 4: terms, sections and enrollments (T-20/T-22). One store shares the lock.
$container['enrollment_catalog'] = $repositories['enrollment'] === 'mysql'
    ? new MySQLCatalogRepository($pdo)
    : new MockCatalogRepository(new JsonStore($runtime . '/enrollment/enrollment.json'));
// Team 5 (T-32): grades. The repository gets the catalog because finalize takes the enrollment lock, then the grades lock.
$container['grades'] = $repositories['grades'] === 'mysql'
    ? new MySQLGradeRepository($pdo, $container['enrollment_catalog'])
    : new MockGradeRepository(new JsonStore($runtime . '/faculty/grades.json'), $container['enrollment_catalog']);
$container['grade_reader'] = new RepositoryGradeReader($container['grades'], $container['enrollment_catalog']);
// Team 3 — academic records (T-11a). Both summaries are real sources now: Team 4's enrollment counts (T-22)
// and Team 5's finalized-grade counts (T-32). StudentDirectory is for Teams 4/5 in-process only.
$container['academic_record_controller'] = new AcademicRecordController(new AcademicRecordService(
    $container['students'], $container['programs'], $container['authorization'],
    new EnrollmentSummarySource($container['enrollment_catalog']), $container['grade_reader']
));
$container['term_controller'] = new TermController(new TermService($container['enrollment_catalog'], $container['authorization']));
// Team 5: faculty profiles (T-30). Real identity: profile active + account active + faculty role, read live.
$employeeNumbers = new EmployeeNumberPolicy(Environment::get('FACULTY_EMPLOYEE_NUMBER_PATTERN'));
$container['faculty_profiles'] = $repositories['faculty'] === 'mysql'
    ? new MySQLFacultyRepository($pdo, $employeeNumbers)
    : new MockFacultyRepository(new JsonStore($runtime . '/faculty/faculty.json'), $employeeNumbers);
$container['faculty_directory'] = new RepositoryFacultyDirectory($container['faculty_profiles']);
// Team 4: instructor assignments (T-21). Team 5's lookup is bound here (T-30).
// Never bind a mock here or select one by config/env: mock lookups exist only under tests/.
$container['faculty_identity'] = new RepositoryFacultyIdentity($container['faculty_profiles'], $container['users']);
$container['section_directory'] = new RepositorySectionDirectory($container['enrollment_catalog'], $container['faculty_identity']);
// B-07: faculty see only students approved in sections they are currently assigned to (built here: needs both directories).
$container['student_visibility'] = new \App\Faculty\Services\RosterFacultyVisibility($container['faculty_directory'], $container['section_directory'], $container['enrollment_catalog']->terms(...));
$container['student_controller'] = new StudentController(new StudentService(
    $container['students'],
    $container['programs'],
    $container['users'],
    $container['authorization'],
    $container['student_visibility'],
    new StudentNumberPolicy(Environment::get('STUDENT_NUMBER_PATTERN'))
));
$container['section_service'] = new SectionService(
    $container['enrollment_catalog'], $container['authorization'], $container['faculty_identity']);
$container['section_controller'] = new SectionController($container['section_service']);
$container['faculty_controller'] = new FacultyController(new FacultyService($container['faculty_profiles'],
    $container['users'], $container['authorization'], $container['section_directory'], $employeeNumbers));
$container['enrollment_controller'] = new EnrollmentController(new EnrollmentService(
    $container['enrollment_catalog'], $container['student_directory'], $container['section_service'], $container['authorization']));
// Team 5 (T-31): class rosters over SectionDirectory (assignment) and StudentDirectory (summary fields).
$container['class_roster_controller'] = new \App\Faculty\Controllers\ClassRosterController(
    new \App\Faculty\Services\ClassRosterService($container['faculty_directory'], $container['section_directory'],
        $container['student_directory'], $container['authorization']));
// Team 5 (T-32): the scale is GradeScale::official() (unconfirmed, B-05) so entry and corrections fail closed,
// unless APP_GRADE_SCALE=demo (owner-approved, academic demonstration only). Test scales exist only under tests/.
$container['grade_controller'] = new GradeController(new GradeService($container['grades'],
    $container['config']['grade_scale'] === 'demo' ? GradeScale::demo() : GradeScale::official(),
    $container['section_directory'], $container['faculty_directory'], $container['student_directory'],
    $container['grade_reader'], $container['authorization']));
foreach (['auth', 'students', 'enrollment', 'faculty'] as $module) {
    $register = require dirname(__DIR__) . '/routes/' . $module . '.php';
    $register($container['router'], $container);
}
return $container;
