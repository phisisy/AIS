<?php
declare(strict_types=1);

use App\Auth\Repositories\MockUserRepository;
use App\Core\JsonStore;

require_once __DIR__ . '/AdminSupport.php';

function adminServer(): AuthHttpServer
{
    return new AuthHttpServer([], 'public/router.php', adminTestRows());
}

function assertNoHashes(array $response): void
{
    foreach (['password_hash', '$2y$', 'auth_version', 'Synthetic-test-pass-42'] as $secret) {
        check(!str_contains($response['body'], $secret), 'Admin response leaked ' . $secret);
    }
}

test('Admin HTTP: anonymous 401, non-admin 403, missing CSRF 403 on every admin route', function (): void {
    $server = adminServer();
    try {
        $routes = [['GET', '/api/admin/users', null], ['GET', '/api/admin/users/3', null],
            ['POST', '/api/admin/users', ['email' => 'n@example.test', 'password' => 'New-passphrase-123', 'roles' => ['student']]],
            ['PATCH', '/api/admin/users/3', ['roles' => ['admin']]], ['PATCH', '/api/admin/users/3/status', ['status' => 'inactive']],
            ['POST', '/api/admin/users/3/reset-password', ['password' => 'New-passphrase-123', 'current_password' => 'x']]];
        foreach ($routes as [$method, $path, $body]) {
            same(401, $server->request($method, $path, $body)['status']);
        }
        foreach (['student@example.test', 'faculty@example.test'] as $email) {
            $server->setCookie('');
            $token = $server->login($email)['json']['data']['csrf_token'];
            foreach ($routes as [$method, $path, $body]) {
                same('{"success":false,"message":"Access denied.","errors":{}}', $server->request($method, $path, $body, $token)['body']);
            }
        }
        same(401, (function () use ($server) { $server->setCookie(''); return $server->login('old-admin@example.test'); })()['status']);
        $server->setCookie('');
        same(200, $server->login('admin@example.test')['status']);
        foreach ($routes as [$method, $path, $body]) {
            if ($method !== 'GET') {
                same('Invalid CSRF token.', $server->request($method, $path, $body)['json']['message']);
            }
        }
        $repository = serverUsers($server); // JSON store in mock mode, the SQL tables on the MySQL switch
        same([], $repository->auditEntries());
        same(['student'], $repository->findById(3)->roles);
    } finally {
        $server->close();
    }
});

test('Admin HTTP: create, list, get, update roles, status and reset over real sessions', function (): void {
    $server = adminServer();
    try {
        $token = $server->login('admin@example.test')['json']['data']['csrf_token'];
        $created = $server->request('POST', '/api/admin/users',
            ['email' => 'New@Example.test', 'password' => 'New-passphrase-123', 'roles' => ['faculty']], $token);
        same(201, $created['status']);
        same(['id' => 6, 'email' => 'new@example.test', 'roles' => ['faculty'], 'status' => 'active'], $created['json']['data']['user']);
        assertNoHashes($created);
        same(409, $server->request('POST', '/api/admin/users',
            ['email' => 'NEW@example.test', 'password' => 'New-passphrase-123', 'roles' => ['faculty']], $token)['status']);
        same(422, $server->request('POST', '/api/admin/users',
            ['email' => 'x@example.test', 'password' => 'New-passphrase-123', 'roles' => ['faculty'], 'id' => 1], $token)['status']);
        $list = $server->request('GET', '/api/admin/users?search=example&per_page=2&page=1');
        same(200, $list['status']);
        same(['page' => 1, 'per_page' => 2, 'total' => 6], $list['json']['data']['pagination']);
        assertNoHashes($list);
        same(422, $server->request('GET', '/api/admin/users?per_page=1000')['status']);
        same('new@example.test', $server->request('GET', '/api/admin/users/6')['json']['data']['user']['email']);
        same(404, $server->request('GET', '/api/admin/users/99')['status']);
        same(404, $server->request('GET', '/api/admin/users/abc')['status']);
        same(['faculty', 'registrar'], $server->request('PATCH', '/api/admin/users/6', ['roles' => ['registrar', 'faculty']], $token)['json']['data']['user']['roles']);
        same('inactive', $server->request('PATCH', '/api/admin/users/6/status', ['status' => 'inactive'], $token)['json']['data']['user']['status']);
        $reset = $server->request('POST', '/api/admin/users/6/reset-password', ['password' => 'Another-passphrase-1', 'current_password' => 'Synthetic-test-pass-42'], $token);
        same(200, $reset['status']);
        assertNoHashes($reset);
        same(409, $server->request('PATCH', '/api/admin/users/1/status', ['status' => 'inactive'], $token)['status']);
        // 422s are not audited; refusals are, with a reason code.
        same(['user.created:success', 'user.created:denied:duplicate_email', 'user.updated:success', 'user.deactivated:success',
            'user.password_reset:success', 'user.deactivated:denied:self_change_not_allowed'],
            auditSummary(serverUsers($server)));
    } finally {
        $server->close();
    }
});

test('Admin HTTP: demotion, deactivation and password reset take effect on the target session', function (): void {
    $server = adminServer();
    try {
        // Target signs in as admin2 and can use admin routes.
        same(200, $server->login('admin2@example.test')['status']);
        $targetCookie = $server->cookie();
        same(200, $server->request('GET', '/api/admin/users')['status']);
        // Admin 1 demotes admin2 in a separate session.
        $server->setCookie('');
        $token = $server->login('admin@example.test')['json']['data']['csrf_token'];
        $adminCookie = $server->cookie();
        same(200, $server->request('PATCH', '/api/admin/users/2', ['roles' => ['registrar']], $token)['status']);
        $server->setCookie($targetCookie);
        same(403, $server->request('GET', '/api/admin/users')['status']);
        same(200, $server->request('GET', '/api/auth/me')['status']);
        // Password reset signs the target out everywhere; the old password stops working.
        $server->setCookie($adminCookie);
        same(200, $server->request('POST', '/api/admin/users/2/reset-password', ['password' => 'Fresh-passphrase-99', 'current_password' => 'Synthetic-test-pass-42'], $token)['status']);
        $server->setCookie($targetCookie);
        same(401, $server->request('GET', '/api/auth/me')['status']);
        $server->setCookie('');
        same(401, $server->login('admin2@example.test')['status']);
        $fresh = $server->request('POST', '/api/auth/login', ['email' => 'admin2@example.test', 'password' => 'Fresh-passphrase-99'], $server->csrf());
        same(200, $fresh['status']);
        $targetCookie = $server->cookie();
        // Deactivation ends that new session on its next request.
        $server->setCookie($adminCookie);
        same(200, $server->request('PATCH', '/api/admin/users/2/status', ['status' => 'inactive'], $token)['status']);
        $server->setCookie($targetCookie);
        same(401, $server->request('GET', '/api/auth/me')['status']);
        // Promotion applies on the next request without re-login.
        $server->setCookie('');
        same(200, $server->login('student@example.test')['status']);
        $studentCookie = $server->cookie();
        same(403, $server->request('GET', '/api/admin/users')['status']);
        $server->setCookie($adminCookie);
        same(200, $server->request('PATCH', '/api/admin/users/3', ['roles' => ['admin', 'student']], $token)['status']);
        $server->setCookie($studentCookie);
        same(200, $server->request('GET', '/api/admin/users')['status']);
    } finally {
        $server->close();
    }
});

test('Admin HTTP: admin-to-admin password reset is refused with a safe 409 and leaves the target session alive', function (): void {
    $server = adminServer();
    try {
        same(200, $server->login('admin2@example.test')['status']);
        $targetCookie = $server->cookie();
        $server->setCookie('');
        $token = $server->login('admin@example.test')['json']['data']['csrf_token'];
        $response = $server->request('POST', '/api/admin/users/2/reset-password',
            ['password' => 'Takeover-passphrase-1', 'current_password' => 'Synthetic-test-pass-42'], $token);
        same(409, $response['status']);
        same('{"success":false,"message":"Administrator passwords cannot be reset through this endpoint.","errors":{}}', $response['body']);
        $server->setCookie($targetCookie);
        same(200, $server->request('GET', '/api/auth/me')['status']);
        $server->setCookie('');
        same(401, $server->request('POST', '/api/auth/login', ['email' => 'admin2@example.test', 'password' => 'Takeover-passphrase-1'], $server->csrf())['status']);
    } finally {
        $server->close();
    }
});

test('Admin HTTP (T-05): audit-logs 401 anonymous, 403 non-admin, 200 admin with filters, 422, no secrets', function (): void {
    $server = adminServer();
    try {
        same(401, $server->request('GET', '/api/admin/audit-logs')['status']);
        $server->setCookie('');
        $server->login('student@example.test');
        same('{"success":false,"message":"Access denied.","errors":{}}', $server->request('GET', '/api/admin/audit-logs')['body']);
        $server->setCookie('');
        $token = $server->login('admin@example.test')['json']['data']['csrf_token'];
        same(0, $server->request('GET', '/api/admin/audit-logs')['json']['data']['pagination']['total']);
        $server->request('POST', '/api/admin/users', ['email' => 'a1@example.test', 'password' => 'New-passphrase-123', 'roles' => ['student']], $token);
        $server->request('POST', '/api/admin/users', ['email' => 'A1@example.test', 'password' => 'New-passphrase-123', 'roles' => ['student']], $token);
        $all = $server->request('GET', '/api/admin/audit-logs');
        same(200, $all['status']);
        same(['page' => 1, 'per_page' => 20, 'total' => 2], $all['json']['data']['pagination']);
        same(['denied', 'success'], array_column($all['json']['data']['entries'], 'outcome'));
        assertNoHashes($all);
        same(['id', 'at', 'actor_id', 'target_id', 'action', 'outcome', 'changes'], array_keys($all['json']['data']['entries'][0]));
        same('user.created', $server->request('GET', '/api/admin/audit-logs?action=user.created&actor_id=1&outcome=success&per_page=1&page=1')['json']['data']['entries'][0]['action']);
        same(1, count($server->request('GET', '/api/admin/audit-logs?outcome=denied')['json']['data']['entries']));
        same(0, count($server->request('GET', '/api/admin/audit-logs?from=2999-01-01T00:00:00Z')['json']['data']['entries']));
        foreach (['page=0', 'per_page=101', 'outcome=x', 'actor_id[]=1', 'action[]=a', 'from=2024-01-01', 'to=2024-02-30T00:00:00Z',
            'from=2024-01-02T00:00:00Z&to=2024-01-01T00:00:00Z', 'search=x', 'outcome=denied&bogus=1'] as $query) {
            same(422, $server->request('GET', '/api/admin/audit-logs?' . $query)['status']);
        }
    } finally {
        $server->close();
    }
});
