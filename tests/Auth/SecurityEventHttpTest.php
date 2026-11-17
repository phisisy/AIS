<?php
declare(strict_types=1);

use App\Auth\Services\SecurityEventLog;
use App\Core\JsonStore;

require_once __DIR__ . '/AdminSupport.php';

function securityEvents(AuthHttpServer $server): array
{
    $path = $server->directory . '/auth/security-events.json';
    return (new SecurityEventLog(new JsonStore($path), $path))->events();
}

test('Security events HTTP: anonymous, invalid session, non-admin and missing CSRF are logged once with unchanged responses', function (): void {
    $server = new AuthHttpServer([], 'public/router.php', adminTestRows());
    try {
        $anonymous = $server->request('GET', '/api/admin/users');
        same([401, '{"success":false,"message":"Authentication required.","errors":{}}'], [$anonymous['status'], $anonymous['body']]);
        $server->setCookie('AIS_SESSION=forgedsessionid0123456789abcdef');
        same(401, $server->request('PATCH', '/api/admin/users/3/status', ['status' => 'inactive'], 'x')['status']);
        $server->setCookie('');
        $studentToken = $server->login('student@example.test')['json']['data']['csrf_token'];
        $denied = $server->request('POST', '/api/admin/users',
            ['email' => 'n@example.test', 'password' => 'Secret-in-body-123', 'roles' => ['admin']], $studentToken);
        same([403, '{"success":false,"message":"Access denied.","errors":{}}'], [$denied['status'], $denied['body']]);
        $server->setCookie('');
        $server->login('admin@example.test');
        same('Invalid CSRF token.', $server->request('PATCH', '/api/admin/users/3', ['roles' => ['admin']], 'bad-token')['json']['message']);
        same(200, $server->request('GET', '/api/admin/users')['status']);

        $events = securityEvents($server);
        same([
            ['unauthenticated', 'GET /api/admin/users', null],
            ['unauthenticated', 'PATCH /api/admin/users/{id}/status', null],
            ['forbidden_role', 'POST /api/admin/users', 3],
            ['csrf_failed', 'PATCH /api/admin/users/{id}', 1],
        ], array_map(static fn (array $e): array => [$e['type'], $e['route'], $e['actor_id']], $events));
        $raw = file_get_contents($server->directory . '/auth/security-events.json');
        foreach (['Secret-in-body-123', 'forgedsession', 'bad-token', $studentToken, 'AIS_SESSION', 'password', 'n@example.test', 'inactive'] as $private) {
            check(!str_contains($raw, $private), 'Security log contains private data: ' . $private);
        }
        // Guard refusals never reach the admin service, so the separate admin audit log stays empty
        // (no duplicate records across the two logs); the successful admin GET added no event either.
        same([], auditSummary(new App\Auth\Repositories\MockUserRepository(new JsonStore($server->directory . '/auth/users.json'))));
    } finally {
        $server->close();
    }
});

test('Security events HTTP: a broken event store changes nothing about access decisions', function (): void {
    $server = new AuthHttpServer([], 'public/router.php', adminTestRows());
    try {
        mkdir($server->directory . '/auth/security-events.json');
        same(401, $server->request('GET', '/api/admin/users')['status']);
        $token = $server->login('student@example.test')['json']['data']['csrf_token'];
        same(403, $server->request('PATCH', '/api/admin/users/3', ['roles' => ['admin']], $token)['status']);
        same(['student'], (new App\Auth\Repositories\MockUserRepository(new JsonStore($server->directory . '/auth/users.json')))->findById(3)->roles);
        $server->setCookie('');
        $adminToken = $server->login('admin@example.test')['json']['data']['csrf_token'];
        same(200, $server->request('GET', '/api/admin/users')['status']);
        same(403, $server->request('PATCH', '/api/admin/users/3', ['roles' => ['admin']])['status']);
        same(200, $server->request('PATCH', '/api/admin/users/3/status', ['status' => 'inactive'], $adminToken)['status']);
    } finally {
        $server->close();
    }
});
