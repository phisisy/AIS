<?php
declare(strict_types=1);

use App\Core\JsonStore;

require_once __DIR__ . '/HttpSupport.php';

function rbacHttpRows(): array
{
    $hash = authTestRows()[0]['password_hash'];
    $row = static fn (int $id, string $email, array $roles, string $status = 'active'): array
        => ['id' => $id, 'email' => $email, 'roles' => $roles, 'status' => $status, 'password_hash' => $hash, 'auth_version' => 1];
    return [
        $row(1, 'student@example.test', ['student']),
        $row(2, 'faculty@example.test', ['faculty']),
        $row(3, 'registrar@example.test', ['registrar']),
        $row(4, 'admin@example.test', ['admin']),
        $row(5, 'multi@example.test', ['faculty', 'registrar']),
        $row(6, 'inactive@example.test', ['admin'], 'inactive'),
    ];
}

function rbacServer(): AuthHttpServer
{
    return new AuthHttpServer([], 'tests/Auth/fixtures/rbac_router.php', rbacHttpRows());
}

test('RBAC HTTP role matrix: 5 users x 6 protected routes, anonymous 401', function (): void {
    $expected = [
        'student@example.test' => ['student'],
        'faculty@example.test' => ['faculty'],
        'registrar@example.test' => ['registrar', 'staff'],
        'admin@example.test' => ['admin', 'staff'],
        'multi@example.test' => ['faculty', 'registrar', 'staff'],
    ];
    $routes = ['student', 'faculty', 'registrar', 'admin', 'staff', 'any'];
    $server = rbacServer();
    try {
        foreach ($routes as $route) {
            same(401, $server->request('GET', '/probe/' . $route)['status']);
        }
        foreach ($expected as $email => $allowed) {
            $server->setCookie('');
            same(200, $server->login($email)['status']);
            foreach ($routes as $route) {
                $response = $server->request('GET', '/probe/' . $route);
                $want = ($route === 'any' || in_array($route, $allowed, true)) ? 200 : 403;
                check($response['status'] === $want, "{$email} {$route}: expected {$want}, got {$response['status']}");
                if ($want === 403) {
                    same('{"success":false,"message":"Access denied.","errors":{}}', $response['body']);
                }
            }
        }
    } finally {
        $server->close();
    }
});

test('RBAC HTTP inactive accounts cannot sign in and protected writes require CSRF after role checks', function (): void {
    $server = rbacServer();
    try {
        same(401, $server->login('inactive@example.test')['status']);
        same(401, $server->request('GET', '/probe/admin')['status']);
        $server->setCookie('');
        $token = $server->login('admin@example.test')['json']['data']['csrf_token'];
        same(403, $server->request('POST', '/probe/admin-write', [])['status']);
        same('Invalid CSRF token.', $server->request('POST', '/probe/admin-write', [], 'bad')['json']['message']);
        same(200, $server->request('POST', '/probe/admin-write', [], $token)['status']);
        // A non-admin with a valid token is still refused, as a role failure.
        $server->setCookie('');
        $studentToken = $server->login('student@example.test')['json']['data']['csrf_token'];
        same('Access denied.', $server->request('POST', '/probe/admin-write', [], $studentToken)['json']['message']);
    } finally {
        $server->close();
    }
});

test('RBAC HTTP ignores client-supplied roles in headers, query and body', function (): void {
    $server = rbacServer();
    try {
        $token = $server->login('student@example.test')['json']['data']['csrf_token'];
        $headers = ['X-Role' => 'admin', 'X-User-Roles' => 'admin', 'X-Forwarded-User' => 'admin@example.test'];
        same(403, $server->request('GET', '/probe/admin?role=admin&roles[]=admin&user_id=4', null, null, $headers)['status']);
        $response = $server->request('POST', '/probe/admin-write', ['roles' => ['admin'], 'role' => 'admin', 'user_id' => 4], $token, $headers);
        same(403, $response['status']);
        same('Access denied.', $response['json']['message']);
        // The session identity is unchanged by those attempts.
        same(1, $server->request('GET', '/probe/any')['json']['data']['user_id']);
        // Route parameters are data, never identity.
        same(1, $server->request('GET', '/probe/records/4')['json']['data']['user_id']);
    } finally {
        $server->close();
    }
});

test('RBAC HTTP role changes apply on the next request and deactivation ends the session', function (): void {
    $server = rbacServer();
    try {
        same(200, $server->login('student@example.test')['status']);
        $store = new JsonStore($server->directory . '/auth/users.json');
        $setRoles = static function (array $roles) use ($store): void {
            $store->transaction(static function (array &$data) use ($roles): void { $data['users'][0]['roles'] = $roles; });
        };
        same(403, $server->request('GET', '/probe/admin')['status']);
        $setRoles(['admin']);
        same(200, $server->request('GET', '/probe/admin')['status']);
        same(403, $server->request('GET', '/probe/student')['status']);
        $setRoles(['student']);
        same(403, $server->request('GET', '/probe/admin')['status']);
        same(200, $server->request('GET', '/probe/student')['status']);
        $store->transaction(static function (array &$data): void { $data['users'][0]['status'] = 'inactive'; });
        same(401, $server->request('GET', '/probe/student')['status']);
        // Reactivation does not revive the destroyed session.
        $store->transaction(static function (array &$data): void { $data['users'][0]['status'] = 'active'; });
        same(401, $server->request('GET', '/probe/student')['status']);
    } finally {
        $server->close();
    }
});

test('RBAC HTTP unknown stored roles fail closed with a generic 500', function (): void {
    $server = rbacServer();
    try {
        same(200, $server->login('student@example.test')['status']);
        $store = new JsonStore($server->directory . '/auth/users.json');
        $store->transaction(static function (array &$data): void { $data['users'][0]['roles'] = ['superuser']; });
        foreach (['/probe/admin', '/probe/student', '/probe/any'] as $path) {
            $response = $server->request('GET', $path);
            same(500, $response['status']);
            same('An internal error occurred.', $response['json']['message']);
            check(!str_contains($response['body'], 'superuser') && !str_contains($response['body'], 'role'), 'No role details in errors.');
        }
    } finally {
        $server->close();
    }
});
