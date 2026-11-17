<?php
declare(strict_types=1);

use App\Auth\Middleware\RouteGuard;
use App\Auth\Models\User;
use App\Auth\Repositories\MockUserRepository;
use App\Auth\Services\AuthorizationService;
use App\Auth\Services\AuthService;
use App\Auth\Services\LoginRateLimiter;
use App\Auth\Services\SecurityEventLog;
use App\Core\HttpException;
use App\Core\JsonStore;
use App\Core\Request;
use App\Core\Response;

require_once __DIR__ . '/AdminSupport.php';

function eventLog(string $path, ?callable $clock = null): SecurityEventLog
{
    return new SecurityEventLog(new JsonStore($path), $path, $clock);
}

test('SecurityEventLog stores only server-built fields and ignores invalid input', function (): void {
    $directory = tempDirectory();
    try {
        $log = eventLog($directory . '/events.json', static fn (): int => 1_700_000_000);
        $log->record('forbidden_role', 'PATCH /api/admin/users/{id}/status', 3);
        $log->record('unauthenticated', 'GET /api/admin/users', null);
        $at = '2023-11-14T22:13:20Z';
        same([
            ['ts' => 1_700_000_000, 'at' => $at, 'last_ts' => 1_700_000_000, 'last_at' => $at, 'count' => 1,
                'type' => 'forbidden_role', 'route' => 'PATCH /api/admin/users/{id}/status', 'actor_id' => 3, 'outcome' => 'refused'],
            ['ts' => 1_700_000_000, 'at' => $at, 'last_ts' => 1_700_000_000, 'last_at' => $at, 'count' => 1,
                'type' => 'unauthenticated', 'route' => 'GET /api/admin/users', 'actor_id' => null, 'outcome' => 'refused'],
        ], $log->events());
        foreach ([['forbidden_role', "GET /api/admin/users\n{\"forged\":1}", 1], ['forbidden_role', 'GET /api/admin/users?x=1', 1],
            ['forbidden_role', 'get /api/admin/users', 1], ['made_up', 'GET /api/admin/users', 1],
            ['forbidden_role', 'GET /api/admin/users', 0], ['forbidden_role', 'GET ' . str_repeat('a', 200), 1]] as [$type, $route, $actor]) {
            $log->record($type, $route, $actor);
        }
        same(2, count($log->events()));
        check(!str_contains(file_get_contents($directory . '/events.json'), 'forged'), 'Injected text must not be stored.');
    } finally {
        removeTestDirectory($directory);
    }
});

test('SecurityEventLog merges repeats within a minute and keeps anonymous floods from evicting identified events', function (): void {
    $directory = tempDirectory();
    try {
        $now = 2_000_000_000;
        $log = eventLog($directory . '/events.json', static function () use (&$now): int { return $now; });
        $log->record('forbidden_role', 'GET /api/admin/users', 7);
        for ($i = 0; $i < 300; $i++) {
            $log->record('unauthenticated', 'GET /api/admin/users', null);
        }
        $events = $log->events();
        same(2, count($events));
        same(300, $events[1]['count']);
        $now += SecurityEventLog::MERGE_SECONDS;
        $log->record('unauthenticated', 'GET /api/admin/users', null);
        same(3, count($log->events()));
        // A long anonymous flood with distinct minutes: anonymous entries are capped, the identified one survives.
        for ($i = 0; $i < SecurityEventLog::MAX_ANONYMOUS + 40; $i++) {
            $now += SecurityEventLog::MERGE_SECONDS;
            $log->record('unauthenticated', 'GET /api/admin/users', null);
        }
        $events = $log->events();
        same(SecurityEventLog::MAX_ANONYMOUS, count(array_filter($events, static fn (array $e): bool => $e['actor_id'] === null)));
        same(7, $events[0]['actor_id']);
        // Identified events have their own cap.
        for ($i = 1; $i <= SecurityEventLog::MAX_IDENTIFIED + 5; $i++) {
            $log->record('forbidden_role', 'GET /api/admin/users', 100 + $i);
        }
        same(SecurityEventLog::MAX_IDENTIFIED, count(array_filter($log->events(), static fn (array $e): bool => $e['actor_id'] !== null)));
        // Age-based retention.
        $now += SecurityEventLog::RETENTION_SECONDS + 1;
        $log->record('unauthenticated', 'GET /api/admin/users', null);
        same(1, count($log->events()));
    } finally {
        removeTestDirectory($directory);
    }
});

test('SecurityEventLog drops an event instead of waiting when the store is busy', function (): void {
    $directory = tempDirectory();
    try {
        $path = $directory . '/events.json';
        $log = eventLog($path);
        $log->record('unauthenticated', 'GET /api/admin/users', null);
        $holder = fopen($path . '.lock', 'c+b');
        check(flock($holder, LOCK_EX), 'Test could not take the lock.');
        try {
            $started = microtime(true);
            $log->record('forbidden_role', 'GET /api/admin/users', 2);
            check(microtime(true) - $started < 0.5, 'record() must not wait for the lock.');
            throws(RuntimeException::class, fn () => (new JsonStore($path))->transaction(static function (array &$d): void {}, false));
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
        }
        same(1, count($log->events()));
        $log->record('forbidden_role', 'GET /api/admin/users', 2);
        same(2, count($log->events()));
    } finally {
        removeTestDirectory($directory);
    }
});

test('SecurityEventLog never throws on broken storage and recovers from a corrupt file', function (): void {
    $directory = tempDirectory();
    try {
        mkdir($directory . '/dir.json');
        eventLog($directory . '/dir.json')->record('unauthenticated', 'GET /api/admin/users', null);
        check(is_dir($directory . '/dir.json'));
        $path = $directory . '/events.json';
        file_put_contents($path, '{"events": [corrupt');
        $log = eventLog($path, static fn (): int => 1_800_000_000);
        same([], $log->events());
        $log->record('unauthenticated', 'GET /api/admin/users', null); // quarantines, this event is lost
        check(is_file($path . '.corrupt-1800000000'), 'Corrupt file must be kept aside.');
        $log->record('unauthenticated', 'GET /api/admin/users', null);
        same(1, count($log->events()));
    } finally {
        removeTestDirectory($directory);
    }
});

test('RouteGuard logs exactly one event per refusal, none for allowed requests or unlogged routes, and refusals survive logging failure', function (): void {
    $directory = tempDirectory();
    try {
        $repository = new MockUserRepository(new JsonStore($directory . '/users.json'));
        $repository->seed(adminTestRows());
        $session = new AuthTestSession();
        $auth = new AuthService($repository, $session, new LoginRateLimiter(new JsonStore($directory . '/attempts.json')));
        $log = eventLog($directory . '/events.json');
        $guard = new RouteGuard($auth, new AuthorizationService(), $session, $log);
        $calls = 0;
        $handler = function (Request $r, array $p, User $u) use (&$calls): Response {
            $calls++;
            throw new HttpException(409, 'Handler refusal');
        };
        $logged = $guard->roles(['admin'], $handler, 'POST /api/admin/users');
        $unlogged = $guard->roles(['admin'], $handler);
        $post = fn (?string $token) => new Request('POST', '/api/admin/users', $token === null ? [] : ['X-CSRF-Token' => $token]);

        same(401, throws(HttpException::class, fn () => $logged($post(null), []))->status);
        $session->current = ['user_id' => 3, 'auth_version' => 1]; // student
        same(403, throws(HttpException::class, fn () => $logged($post($session->csrfToken()), []))->status);
        $session->current = ['user_id' => 1, 'auth_version' => 1]; // admin, no CSRF
        same('Invalid CSRF token.', throws(HttpException::class, fn () => $logged($post(null), []))->getMessage());
        same(['unauthenticated:', 'forbidden_role:3', 'csrf_failed:1'],
            array_map(static fn (array $e): string => $e['type'] . ':' . $e['actor_id'], $log->events()));
        same(409, throws(HttpException::class, fn () => $logged($post($session->csrfToken()), []))->status);
        same(1, $calls);
        $session->current = null;
        same(401, throws(HttpException::class, fn () => $unlogged($post(null), []))->status);
        same(3, count($log->events()));
        throws(InvalidArgumentException::class, fn () => $guard->roles(['admin'], $handler, "GET /x\nforged"));
        throws(InvalidArgumentException::class, fn () => (new RouteGuard($auth, new AuthorizationService(), $session))->roles(['admin'], $handler, 'GET /api/admin/users'));

        mkdir($directory . '/broken.json');
        $broken = new RouteGuard($auth, new AuthorizationService(), $session, eventLog($directory . '/broken.json'));
        $route = $broken->roles(['admin'], $handler, 'POST /api/admin/users');
        same(401, throws(HttpException::class, fn () => $route($post(null), []))->status);
        $session->current = ['user_id' => 3, 'auth_version' => 1];
        same(403, throws(HttpException::class, fn () => $route($post($session->csrfToken()), []))->status);
        same(1, $calls);
    } finally {
        removeTestDirectory($directory);
    }
});
