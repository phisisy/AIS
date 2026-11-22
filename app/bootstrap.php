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
use App\Enrollment\Contracts\UnavailableFacultyIdentity;
use App\Enrollment\Repositories\MockCatalogRepository;
use App\Enrollment\Repositories\MySQLCatalogRepository;
use App\Enrollment\Services\TermService;
use App\Enrollment\Services\SectionService;
use App\Enrollment\Services\RepositorySectionDirectory;
use App\Students\Contracts\DenyAllFacultyVisibility;
use App\Students\Contracts\UnavailableSummarySource;
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
    // Faculty stays on mock until T-30 is merged. The gate runs before the runtime directory is touched.
    if ($repositories['faculty'] === 'mysql' || $container['config']['environment'] !== 'testing'
        || $db['database'] !== 'ais_test' || $db['username'] !== 'ais_test_app'
        || !in_array($db['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('MySQL repositories are not available yet.');
    }
    $pdo = Database::connect($db);
    $live = $pdo->query('SELECT DATABASE() AS db_name, CURRENT_USER() AS account')->fetch();
    if (!is_array($live) || $live['db_name'] !== 'ais_test' || !str_starts_with((string) $live['account'], 'ais_test_app@')) {
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
// Team 3 — student records (T-10). Faculty visibility denies everything until T-31 wires a
// verified class-assignment source. An invalid STUDENT_NUMBER_PATTERN fails closed here.
// Programs and students switch together (programs is the students' FK parent).
$container['programs'] = $repositories['students'] === 'mysql'
    ? new MySQLProgramCatalog($pdo)
    : new JsonProgramCatalog(new JsonStore($runtime . '/students/programs.json'));
$container['student_visibility'] = new DenyAllFacultyVisibility();
$container['students'] = $repositories['students'] === 'mysql'
    ? new MySQLStudentRepository($pdo)
    : new MockStudentRepository(new JsonStore($runtime . '/students/students.json'));
$container['student_controller'] = new StudentController(new StudentService(
    $container['students'],
    $container['programs'],
    $container['users'],
    $container['authorization'],
    $container['student_visibility'],
    new StudentNumberPolicy(Environment::get('STUDENT_NUMBER_PATTERN'))
));
// Team 3 — academic records (T-11a). Enrollment/grade summaries stay "unavailable" until
// Teams 4/5 provide verified sources (T-11b). StudentDirectory is for Teams 4/5 in-process only.
$container['academic_record_controller'] = new AcademicRecordController(new AcademicRecordService(
    $container['students'], $container['programs'], $container['authorization'],
    new UnavailableSummarySource(), new UnavailableSummarySource()
));
$container['student_directory'] = new RepositoryStudentDirectory($container['students'], $container['users']);
// Team 4: terms and sections (T-20). One store shares the lock; T-22 adds enrollments here.
$container['enrollment_catalog'] = $repositories['enrollment'] === 'mysql'
    ? new MySQLCatalogRepository($pdo)
    : new MockCatalogRepository(new JsonStore($runtime . '/enrollment/enrollment.json'));
$container['term_controller'] = new TermController(new TermService($container['enrollment_catalog'], $container['authorization']));
// Team 4: instructor assignments (T-21). Verifies nobody until T-30 binds Team 5's lookup (owner 2026-11-20).
// Never bind a mock here or select one by config/env: mock lookups exist only under tests/.
$container['faculty_identity'] = new UnavailableFacultyIdentity();
// T-30 safety rule (owner OQ-G, configuration check only): with a real faculty lookup, assignment faculty ids
// are FK-checked, so enrollment on MySQL then requires faculty on MySQL. Fails closed; inert until T-30.
if ($repositories['enrollment'] === 'mysql' && $repositories['faculty'] !== 'mysql'
    && !$container['faculty_identity'] instanceof UnavailableFacultyIdentity) {
    throw new RuntimeException('Invalid repository configuration.');
}
$container['section_directory'] = new RepositorySectionDirectory($container['enrollment_catalog'], $container['faculty_identity']);
$container['section_controller'] = new SectionController(new SectionService(
    $container['enrollment_catalog'], $container['authorization'], $container['faculty_identity']));
foreach (['auth', 'students', 'enrollment', 'faculty'] as $module) {
    $register = require dirname(__DIR__) . '/routes/' . $module . '.php';
    $register($container['router'], $container);
}
return $container;
