<?php
declare(strict_types=1);

require_once __DIR__ . '/MySQLTestSupport.php';
require_once dirname(__DIR__) . '/Auth/AdminSupport.php';
require_once dirname(__DIR__) . '/Students/StudentSupport.php';
require_once dirname(__DIR__) . '/Enrollment/EnrollmentSupport.php';

function failClosedBody(array $response, array $secrets = []): void
{
    same(500, $response['status']);
    same('An internal error occurred.', $response['json']['message']);
    foreach (array_merge(['SQLSTATE', 'PDO', 'ais_test', 'ais_test_app', (string) getenv('DB_HOST')], $secrets) as $secret) {
        if ($secret !== '') { check(!str_contains($response['body'], $secret), 'Database detail leaked in HTTP response.'); }
    }
}

/** One injected model-invalid row per call, so each fault passes or fails on its own. */
function failClosedInvalidRow(string $fault): void
{
    $previous = getenv('AIS_HTTP_MYSQL');
    try {
        putenv('AIS_HTTP_MYSQL=C4');
        $server = new AuthHttpServer([], 'public/router.php', adminTestRows());
        try {
            (new App\Students\Repositories\JsonProgramCatalog(
                new App\Core\JsonStore($server->directory . '/students/programs.json')))->seed(studentPrograms());
            same(200, $server->login('admin@example.test')['status']);
            $token = $server->csrf();
            $owner = MySQLTestSupport::connect();
            if ($fault === 'hash') {
                $owner->prepare('UPDATE users SET password_hash = ? WHERE id = 3')->execute([str_repeat('x', 30)]);
                $server->setCookie('');
                failClosedBody($server->request('POST', '/api/auth/login',
                    ['email' => 'student@example.test', 'password' => 'Synthetic-test-pass-42'], $server->csrf()));
            } elseif ($fault === 'audit_timestamp') {
                // SR-1: a valid row reads back as its exact ISO time; only its `at` is then corrupted.
                $owner->exec("INSERT INTO audit_logs (id, at, actor_id, target_id, action, outcome, changes) SELECT COALESCE(MAX(id), 0) + 1,"
                    . " '2026-11-25 08:40:56', 1, 3, 'user.updated', 'success', '{}' FROM audit_logs");
                $baseline = $server->request('GET', '/api/admin/audit-logs');
                same(200, $baseline['status']);
                check(str_contains($baseline['body'], '"2026-11-25T08:40:56Z"'), 'SR-1 baseline audit row missing.');
                $owner->exec("SET SESSION sql_mode = ''");
                $owner->exec("UPDATE audit_logs SET at = '0000-00-00 00:00:00' WHERE at = '2026-11-25 08:40:56'");
                failClosedBody($server->request('GET', '/api/admin/audit-logs'), ['0000-00-00', '0000-00-00T00:00:00Z']);
                // The other read path (auditEntries) maps rows through the same auditRow()/isoTime().
                $refused = throws(RuntimeException::class,
                    static fn () => (new App\Auth\Repositories\MySQLUserRepository(MySQLTestSupport::connectApp()))->auditEntries());
                same(['Invalid audit store.', null], [$refused->getMessage(), $refused->getPrevious()]);
            } else {
                same(201, $server->request('POST', '/api/registrar/terms', termInput(), $token)['status']);
                if ($fault === 'timestamp') {
                    $owner->exec("SET SESSION sql_mode = ''");
                    $owner->exec("UPDATE terms SET created_at = '0000-00-00 00:00:00' WHERE id = 1");
                    failClosedBody($server->request('GET', '/api/terms'));
                } else {
                    same(201, $server->request('POST', '/api/registrar/sections', sectionInput(), $token)['status']);
                    $owner->exec('UPDATE section_meetings SET position = 1 WHERE section_id = 1 AND position = 0');
                    failClosedBody($server->request('GET', '/api/sections/1'));
                }
            }
            foreach (['auth/users.json', 'students/students.json', 'students/programs.json',
                'enrollment/enrollment.json'] as $path) {
                check(!is_file($server->directory . '/' . $path), 'MySQL module fell back to JSON.');
            }
        } finally { $server->close(); }
    } finally { putenv($previous === false ? 'AIS_HTTP_MYSQL' : 'AIS_HTTP_MYSQL=' . $previous); }
}

if (is_string(getenv('AIS_MYSQL_TEST_APP_PASSWORD')) && MySQLTestSupport::enabled()) {
    test('T-60 V9: invalid password hash row fails closed without SQL or fallback',
        static fn () => failClosedInvalidRow('hash'));
    test('T-60 V9: zero DATETIME timestamp row fails closed without SQL or fallback',
        static fn () => failClosedInvalidRow('timestamp'));
    test('T-60 V9: invalid meeting position row fails closed without SQL or fallback',
        static fn () => failClosedInvalidRow('meeting'));
    test('SR-1 MySQL: a zero audit_logs.at row fails closed on the admin audit endpoint without the stored value',
        static fn () => failClosedInvalidRow('audit_timestamp'));

    test('T-60 V9: wrong password and closed port give generic 500 on every route without security log', static function (): void {
        $previous = getenv('AIS_HTTP_MYSQL');
        try {
            putenv('AIS_HTTP_MYSQL');
            foreach (['wrong_password', 'closed_port'] as $fault) {
                $overrides = ['APP_REPOSITORY_AUTH' => 'mysql', 'APP_REPOSITORY_FACULTY' => 'mock',
                    'DB_DATABASE' => 'ais_test', 'DB_USERNAME' => 'ais_test_app',
                    'DB_PASSWORD' => $fault === 'wrong_password' ? 'deliberately-wrong-test-password' :
                        (string) getenv('AIS_MYSQL_TEST_APP_PASSWORD'),
                    'DB_PORT' => $fault === 'closed_port' ? '1' : (string) getenv('DB_PORT')];
                $server = new AuthHttpServer($overrides);
                try {
                    unlink($server->directory . '/auth/users.json');
                    unlink($server->directory . '/auth/users.json.lock');
                    foreach (['/', '/api/admin/users', '/api/auth/me'] as $path) {
                        failClosedBody($server->request('GET', $path), ['deliberately-wrong-test-password']);
                    }
                    check(!is_file($server->directory . '/auth/users.json'), 'Outage created a JSON fallback.');
                    check(!is_file($server->directory . '/auth/security-events.json'), 'Outage logged an anonymous refusal.');
                } finally { $server->close(); }
            }
        } finally { putenv($previous === false ? 'AIS_HTTP_MYSQL' : 'AIS_HTTP_MYSQL=' . $previous); }
    });
}
