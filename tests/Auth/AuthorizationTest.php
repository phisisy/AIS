<?php
declare(strict_types=1);

use App\Auth\Middleware\RouteGuard;
use App\Auth\Models\User;
use App\Auth\Repositories\MockUserRepository;
use App\Auth\Services\AuthorizationService;
use App\Auth\Services\AuthService;
use App\Auth\Services\LoginRateLimiter;
use App\Core\HttpException;
use App\Core\JsonStore;
use App\Core\Request;
use App\Core\Response;

require_once __DIR__ . '/HttpSupport.php';

function rbacUser(array $roles, string $status = 'active', int $id = 10): User
{
    static $hash = null;
    $hash ??= password_hash('x', PASSWORD_DEFAULT);
    return new User($id, "u{$id}@example.test", $roles, $status, $hash);
}

test('AuthorizationService role matrix: each role reaches only allowlists naming it, no hierarchy', function (): void {
    $authorization = new AuthorizationService();
    $allowlists = [['student'], ['faculty'], ['registrar'], ['admin'], ['registrar', 'admin'], ['student', 'faculty']];
    foreach (AuthorizationService::ROLES as $role) {
        $user = rbacUser([$role]);
        foreach ($allowlists as $allowed) {
            $expected = in_array($role, $allowed, true);
            same($expected, $authorization->hasAnyRole($user, $allowed));
            if ($expected) {
                $authorization->requireRole($user, $allowed);
            } else {
                $error = throws(HttpException::class, fn () => $authorization->requireRole($user, $allowed));
                same(403, $error->status);
                same('Access denied.', $error->getMessage());
                same([], $error->errors);
            }
        }
    }
});

test('AuthorizationService multi-role users get the union of their roles only', function (): void {
    $authorization = new AuthorizationService();
    $user = rbacUser(['faculty', 'registrar']);
    $authorization->requireRole($user, ['faculty']);
    $authorization->requireRole($user, ['registrar']);
    $authorization->requireRole($user, ['admin', 'registrar']);
    same(403, throws(HttpException::class, fn () => $authorization->requireRole($user, ['admin']))->status);
    same(403, throws(HttpException::class, fn () => $authorization->requireRole($user, ['student']))->status);
});

test('AuthorizationService denies empty, unknown-only and non-string allowlists and rejects inactive users with 401', function (): void {
    $authorization = new AuthorizationService();
    $admin = rbacUser(['admin']);
    foreach ([[], ['superuser'], ['Admin'], ['ADMIN'], [' admin'], [1], [null], [['admin']], ['*']] as $allowed) {
        same(403, throws(HttpException::class, fn () => $authorization->requireRole($admin, $allowed))->status);
    }
    $inactive = rbacUser(['admin'], 'inactive');
    $error = throws(HttpException::class, fn () => $authorization->requireRole($inactive, ['admin']));
    same(401, $error->status);
    same('Authentication required.', $error->getMessage());
    same(false, $authorization->isOwnerOrHasRole($inactive, $inactive->id, ['admin']));
});

test('AuthorizationService validAllowlist rejects empty, unknown, mis-cased and non-list allowlists at registration', function (): void {
    same(['registrar', 'admin'], AuthorizationService::validAllowlist(['registrar', 'admin', 'registrar']));
    foreach ([[], ['superuser'], ['Admin'], ['admin', 'root'], [1], ['admin' => 'admin'], [1 => 'admin']] as $allowed) {
        throws(InvalidArgumentException::class, fn () => AuthorizationService::validAllowlist($allowed));
    }
});

test('AuthorizationService ownership helper prevents horizontal access', function (): void {
    $authorization = new AuthorizationService();
    $student = rbacUser(['student'], 'active', 21);
    $registrar = rbacUser(['registrar'], 'active', 22);
    same(true, $authorization->isOwnerOrHasRole($student, 21, ['registrar', 'admin']));
    same(false, $authorization->isOwnerOrHasRole($student, 99, ['registrar', 'admin']));
    same(true, $authorization->isOwnerOrHasRole($registrar, 99, ['registrar', 'admin']));
    same(false, $authorization->isOwnerOrHasRole($registrar, 99, []));
    same(true, $authorization->isOwnerOrHasRole($student, 21, []));
    throws(InvalidArgumentException::class, fn () => $authorization->isOwnerOrHasRole($registrar, 99, ['Registrar']));
    $authorization->requireOwnerOrRole($student, 21, ['registrar']);
    $authorization->requireOwnerOrRole($registrar, 99, ['registrar']);
    $error = throws(HttpException::class, fn () => $authorization->requireOwnerOrRole($student, 99, ['registrar', 'admin']));
    same([404, 'Not found.', []], [$error->status, $error->getMessage(), $error->errors]);
});

test('Every application route is guarded except the explicitly public ones', function (): void {
    $directory = tempDirectory();
    $previous = getenv('APP_RUNTIME_PATH');
    try {
        putenv('APP_RUNTIME_PATH=' . $directory);
        $container = require dirname(__DIR__, 2) . '/app/bootstrap.php';
        $property = new ReflectionProperty(App\Core\Router::class, 'routes');
        $routes = $property->getValue($container['router']);
        // Public by design: health check and the T-01 auth endpoints (which check identity/CSRF themselves).
        $public = ['GET /', 'GET /api/auth/csrf', 'POST /api/auth/login', 'POST /api/auth/logout', 'GET /api/auth/me'];
        $seen = [];
        foreach ($routes as $route) {
            $key = $route['method'] . ' ' . $route['path'];
            $seen[] = $key;
            check(in_array($key, $public, true) || $route['handler'] instanceof App\Auth\Middleware\GuardedHandler,
                "Route {$key} is not wrapped by \$container['guard'].");
        }
        foreach ($public as $key) {
            check(in_array($key, $seen, true), "Expected public route {$key} is missing.");
        }
    } finally {
        putenv($previous === false ? 'APP_RUNTIME_PATH' : 'APP_RUNTIME_PATH=' . $previous);
        removeTestDirectory($directory);
    }
});

test('User model rejects unknown or duplicated stored roles so they can never grant access', function (): void {
    foreach ([['superuser'], ['Admin'], ['admin', 'admin'], [], ['admin' => 'admin']] as $roles) {
        throws(RuntimeException::class, fn () => rbacUser($roles));
    }
});

test('RouteGuard checks authentication, then role, then CSRF, and passes a fresh user to the handler', function (): void {
    $directory = tempDirectory();
    try {
        $rows = authTestRows();
        $rows[] = ['id' => 3, 'email' => 'admin@example.test', 'roles' => ['admin'], 'status' => 'active',
            'password_hash' => $rows[0]['password_hash'], 'auth_version' => 1];
        $store = new JsonStore($directory . '/users.json');
        $repository = new MockUserRepository($store);
        $repository->seed($rows);
        $session = new AuthTestSession();
        $auth = new AuthService($repository, $session, new LoginRateLimiter(new JsonStore($directory . '/attempts.json')));
        $guard = new RouteGuard($auth, new AuthorizationService(), $session);
        $calls = 0;
        $seen = null;
        $handler = function (Request $request, array $params, User $user) use (&$calls, &$seen): Response {
            $calls++;
            $seen = $user;
            return Response::success(['params' => $params]);
        };
        $write = $guard->roles(['admin'], $handler);
        $post = fn (?string $token) => new Request('POST', '/x', $token === null ? [] : ['X-CSRF-Token' => $token]);

        $error = throws(HttpException::class, fn () => $write($post(null), []));
        same([401, 'Authentication required.'], [$error->status, $error->getMessage()]);
        $session->current = ['user_id' => 1, 'auth_version' => 1];
        // Wrong role is reported as a role failure, not a CSRF failure.
        $error = throws(HttpException::class, fn () => $write($post(null), []));
        same([403, 'Access denied.'], [$error->status, $error->getMessage()]);
        $session->current = ['user_id' => 3, 'auth_version' => 1];
        $error = throws(HttpException::class, fn () => $write($post(null), []));
        same([403, 'Invalid CSRF token.'], [$error->status, $error->getMessage()]);
        same(0, $calls);
        same(200, $write($post($session->csrfToken()), ['id' => 5])->status);
        same(1, $calls);
        same(3, $seen->id);
        // GET needs no CSRF token.
        same(200, $guard->roles(['admin'], $handler)(new Request('GET', '/x'), [])->status);
        // The user is reloaded: a role change in storage applies to the next request.
        $store->transaction(static function (array &$data): void { $data['users'][2]['roles'] = ['registrar']; });
        same(403, throws(HttpException::class, fn () => $guard->roles(['admin'], $handler)(new Request('GET', '/x'), []))->status);
        same(200, $guard->roles(['registrar'], $handler)(new Request('GET', '/x'), [])->status);
        same(200, $guard->authenticated($handler)(new Request('GET', '/x'), [])->status);
        throws(InvalidArgumentException::class, fn () => $guard->roles(['superadmin'], $handler));
        throws(InvalidArgumentException::class, fn () => $guard->roles([], $handler));
    } finally {
        removeTestDirectory($directory);
    }
});
