<?php
declare(strict_types=1);

require_once __DIR__ . '/MySQLTestSupport.php';
require_once dirname(__DIR__) . '/Auth/AdminSupport.php';
require_once dirname(__DIR__) . '/Students/StudentSupport.php';
require_once dirname(__DIR__) . '/Enrollment/EnrollmentSupport.php';

function wiringMysqlSharedPdo(string $mode): void
{
    $runtime = tempDirectory();
    $names = ['APP_ENV', 'APP_REPOSITORY', 'APP_REPOSITORY_AUTH', 'APP_REPOSITORY_STUDENTS',
        'APP_REPOSITORY_ENROLLMENT', 'APP_REPOSITORY_FACULTY', 'APP_REPOSITORY_GRADES', 'APP_RUNTIME_PATH', 'DB_USERNAME', 'DB_PASSWORD'];
    $previous = array_combine($names, array_map('getenv', $names));
    try {
        $settings = ['APP_ENV' => 'testing', 'APP_REPOSITORY' => 'mock', 'APP_REPOSITORY_AUTH' => 'mysql',
            // Same combinations as the HTTP switch (config rules T-22/T-30/T-32): C3 = students+enrollment+faculty, C4 = C3 + grades.
            'APP_REPOSITORY_STUDENTS' => in_array($mode, ['C2', 'C3', 'C4'], true) ? 'mysql' : 'mock',
            'APP_REPOSITORY_ENROLLMENT' => in_array($mode, ['C3', 'C4'], true) ? 'mysql' : 'mock',
            'APP_REPOSITORY_FACULTY' => in_array($mode, ['C3', 'C4'], true) ? 'mysql' : 'mock',
            'APP_REPOSITORY_GRADES' => $mode === 'C4' ? 'mysql' : 'mock', 'APP_RUNTIME_PATH' => $runtime,
            'DB_USERNAME' => 'ais_test_app', 'DB_PASSWORD' => (string) getenv('AIS_MYSQL_TEST_APP_PASSWORD')];
        foreach ($settings as $key => $value) { putenv($key . '=' . $value); }
        $container = require dirname(__DIR__, 2) . '/app/bootstrap.php';
        $adapters = [$container['users']];
        if ($settings['APP_REPOSITORY_STUDENTS'] === 'mysql') {
            $adapters[] = $container['students']; $adapters[] = $container['programs'];
        }
        if ($settings['APP_REPOSITORY_ENROLLMENT'] === 'mysql') { $adapters[] = $container['enrollment_catalog']; }
        if ($settings['APP_REPOSITORY_FACULTY'] === 'mysql') { $adapters[] = $container['faculty_profiles']; }
        if ($settings['APP_REPOSITORY_GRADES'] === 'mysql') { $adapters[] = $container['grades']; }
        $pdo = (new ReflectionProperty($adapters[0], 'pdo'))->getValue($adapters[0]);
        check($pdo instanceof PDO, 'Auth adapter did not receive a PDO.');
        foreach ($adapters as $adapter) {
            same($pdo, (new ReflectionProperty($adapter, 'pdo'))->getValue($adapter));
        }
    } finally {
        foreach ($previous as $key => $value) { putenv($value === false ? $key : $key . '=' . $value); }
        removeTestDirectory($runtime);
    }
}

if (is_string(getenv('AIS_MYSQL_TEST_APP_PASSWORD')) && MySQLTestSupport::enabled()) {
    foreach (['C1', 'C2', 'C3', 'C4'] as $mode) {
        test('T-60 V11 wiring ' . $mode . ': real HTTP, persistence, file boundaries and shared PDO, three rounds',
            static function () use ($mode): void {
                $previous = getenv('AIS_HTTP_MYSQL');
                try {
                    putenv('AIS_HTTP_MYSQL=' . $mode);
                    for ($round = 1; $round <= 3; $round++) {
                        $server = new AuthHttpServer([], 'public/router.php', adminTestRows());
                        try {
                            (new App\Students\Repositories\JsonProgramCatalog(
                                new App\Core\JsonStore($server->directory . '/students/programs.json')))->seed(studentPrograms());
                            same(200, $server->request('GET', '/')['status']);
                            same(404, $server->request('GET', '/no-such-route')['status']);
                            same(405, $server->request('POST', '/', [])['status']);
                            same(404, $server->request('GET', '/.env')['status']);
                            same(401, $server->request('GET', '/api/admin/users')['status']);
                            $admin = $server->login('admin@example.test');
                            same(200, $admin['status']);
                            $adminCookie = $server->cookie();
                            $csrf = $admin['json']['data']['csrf_token'];
                            same(200, $server->request('GET', '/api/admin/users')['status']);
                            $created = $server->request('POST', '/api/admin/users',
                                ['email' => 'wiring@example.test', 'password' => 'Wiring-passphrase-42',
                                    'roles' => ['student']], $csrf);
                            same(201, $created['status']);
                            $student = $server->request('POST', '/api/students', anaInput(['user_id' => 3]), $csrf);
                            same(201, $student['status']);
                            $term = $server->request('POST', '/api/registrar/terms', termInput(), $csrf);
                            same(201, $term['status']);
                            $section = $server->request('POST', '/api/registrar/sections',
                                sectionInput(['term_id' => 1]), $csrf);
                            same(201, $section['status']);
                            // T-30: a real faculty lookup. An unknown faculty id is refused in every combination.
                            $assign = '/api/registrar/sections/1/instructors';
                            same(422, $server->request('POST', $assign, ['faculty_id' => 999], $csrf)['status']);
                            $owner = MySQLTestSupport::connect();
                            same(0, (int) $owner->query('SELECT COUNT(*) FROM audit_logs WHERE outcome = \'denied\'')->fetchColumn());
                            same(1, (int) $owner->query("SELECT COUNT(*) FROM users WHERE email = 'wiring@example.test'")->fetchColumn());
                            check((int) $owner->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() >= 1);
                            $mysqlStudents = in_array($mode, ['C2', 'C3', 'C4'], true);
                            $mysqlEnrollment = in_array($mode, ['C3', 'C4'], true);
                            if ($mysqlStudents) {
                                same(1, (int) $owner->query('SELECT COUNT(*) FROM students')->fetchColumn());
                                check((int) $owner->query('SELECT COUNT(*) FROM student_academic_history')->fetchColumn() >= 1);
                            }
                            if ($mysqlEnrollment) {
                                same(1, (int) $owner->query('SELECT COUNT(*) FROM terms')->fetchColumn());
                                same(1, (int) $owner->query('SELECT COUNT(*) FROM sections')->fetchColumn());
                                same(0, (int) $owner->query('SELECT COUNT(*) FROM section_instructor_history')->fetchColumn());
                                // Faculty import is unsupported, so the tester seeds an active faculty (user 4 holds the faculty role).
                                $owner->prepare('INSERT INTO faculty (id,user_id,employee_number,first_name,last_name,department,status,created_at,updated_at) '
                                    . 'VALUES (?,?,?,?,?,?,?,?,?)')->execute([10, 4, 'WIRING-10', 'Test', 'Faculty', 'Science',
                                        'active', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
                                same(201, $server->request('POST', $assign, ['faculty_id' => 10], $csrf)['status']);
                                same(['assigned'], $owner->query(
                                    'SELECT action FROM section_instructor_history ORDER BY seq')->fetchAll(PDO::FETCH_COLUMN));
                                same(1, (int) $owner->query('SELECT COUNT(*) FROM section_instructors')->fetchColumn());
                                same(200, $server->request('DELETE', $assign . '/10', null, $csrf)['status']);
                                same(['assigned', 'unassigned'], $owner->query(
                                    'SELECT action FROM section_instructor_history ORDER BY seq')->fetchAll(PDO::FETCH_COLUMN));
                                same(0, (int) $owner->query('SELECT COUNT(*) FROM section_instructors')->fetchColumn());
                            }
                            $paths = ['auth' => 'auth/users.json', 'students' => 'students/students.json',
                                'enrollment' => 'enrollment/enrollment.json'];
                            foreach ($paths as $module => $path) {
                                $mysql = $module === 'auth' || ($module === 'students' && $mysqlStudents)
                                    || ($module === 'enrollment' && $mysqlEnrollment);
                                same(!$mysql, is_file($server->directory . '/' . $path));
                            }
                            same(!$mysqlStudents, is_file($server->directory . '/students/programs.json'));
                            check(is_dir($server->directory . '/sessions'));
                            check(is_file($server->directory . '/auth/login-attempts.json'));
                            check(is_file($server->directory . '/auth/security-events.json'));
                            // Two sessions for the same target: deactivation must invalidate the other one.
                            $server->setCookie('');
                            same(200, $server->login('student@example.test')['status']);
                            $firstCookie = $server->cookie();
                            $server->setCookie('');
                            same(200, $server->login('student@example.test')['status']);
                            $otherCookie = $server->cookie();
                            $server->setCookie($adminCookie);
                            same(200, $server->request('PATCH', '/api/admin/users/3/status',
                                ['status' => 'inactive'], $csrf)['status']);
                            same(2, (int) $owner->query('SELECT auth_version FROM users WHERE id = 3')->fetchColumn());
                            $server->setCookie($otherCookie);
                            same(401, $server->request('GET', '/api/auth/me')['status']);
                            $server->setCookie($firstCookie);
                            same(401, $server->request('GET', '/api/auth/me')['status']);
                            wiringMysqlSharedPdo($mode);
                        } finally { $server->close(); }
                    }
                } finally { putenv($previous === false ? 'AIS_HTTP_MYSQL' : 'AIS_HTTP_MYSQL=' . $previous); }
            });
    }
}
