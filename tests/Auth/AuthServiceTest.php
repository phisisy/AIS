<?php
declare(strict_types=1);

use App\Auth\Models\User;
use App\Auth\Repositories\MockUserRepository;
use App\Auth\Services\AuthService;
use App\Auth\Services\LoginRateLimiter;
use App\Auth\SessionInterface;
use App\Core\HttpException;
use App\Core\JsonStore;

require_once __DIR__ . '/HttpSupport.php';

final class AuthTestSession implements SessionInterface
{
    public ?array $current = null;
    public int $logins = 0;
    public int $destroys = 0;

    public function start(): void {}
    public function csrfToken(): string { return str_repeat('a', 64); }
    public function assertCsrf(?string $token): void
    {
        if ($token !== $this->csrfToken()) {
            throw new HttpException(403, 'Invalid CSRF token.');
        }
    }
    public function login(int $userId, int $authVersion): void
    {
        $this->logins++;
        $this->current = ['user_id' => $userId, 'auth_version' => $authVersion];
    }
    public function identity(): ?array { return $this->current; }
    public function destroy(): void { $this->destroys++; $this->current = null; }
    public function close(): void {}
}

function authServiceFixture(string $directory): array
{
    $store = new JsonStore($directory . '/users.json');
    $repository = new MockUserRepository($store);
    $repository->seed(authTestRows());
    $session = new AuthTestSession();
    $limiter = new LoginRateLimiter(new JsonStore($directory . '/attempts.json'));
    return [new AuthService($repository, $session, $limiter), $session, $store];
}

test('AuthService logs in with normalized email and exposes only public user fields', function (): void {
    $directory = tempDirectory();
    try {
        [$service, $session] = authServiceFixture($directory);
        $user = $service->login('  STUDENT@EXAMPLE.TEST ', 'Synthetic-test-pass-42', '192.0.2.1');
        check($user instanceof User);
        same(['user_id' => 1, 'auth_version' => 1], $session->current);
        same(1, $session->logins);
        same(['id' => 1, 'email' => 'student@example.test', 'roles' => ['student'], 'status' => 'active'], $user->toPublicArray());
        same($user->toPublicArray(), json_decode(json_encode($user, JSON_THROW_ON_ERROR), true));
    } finally {
        removeTestDirectory($directory);
    }
});

test('AuthService invalid credentials consistently deny without establishing a session', function (): void {
    $directory = tempDirectory();
    try {
        [$service, $session] = authServiceFixture($directory);
        $cases = [
            ['unknown@example.test', 'Synthetic-test-pass-42'],
            ['student@example.test', 'wrong-password'],
            ['inactive@example.test', 'Synthetic-test-pass-42'],
            [[], 'Synthetic-test-pass-42'],
            ['student@example.test', []],
            [false, 42],
            ['', ''],
            ['student@example.test', str_repeat('a', 73)],
            ['student@example.test', "bad\0password"],
        ];
        foreach ($cases as $index => [$email, $password]) {
            $exception = throws(HttpException::class, fn () => $service->login($email, $password, '192.0.2.' . ($index + 1)));
            same(401, $exception->status);
            same('Invalid email or password.', $exception->getMessage());
            same([], $exception->errors);
            same(null, $session->current);
        }
        same(0, $session->logins);
    } finally {
        removeTestDirectory($directory);
    }
});

test('AuthService requireUser reloads data and rejects revoked or deleted users', function (): void {
    $directory = tempDirectory();
    try {
        [$service, $session, $store] = authServiceFixture($directory);
        same(401, throws(HttpException::class, fn () => $service->requireUser())->status);
        $session->current = ['user_id' => 1, 'auth_version' => 1];
        same('student@example.test', $service->requireUser()->email);
        $store->transaction(static function (array &$data): void { $data['users'][0]['email'] = 'changed@example.test'; });
        same('changed@example.test', $service->requireUser()->email);
        foreach (['version', 'inactive', 'deleted'] as $change) {
            $session->current = ['user_id' => 1, 'auth_version' => 1];
            $store->transaction(static function (array &$data) use ($change): void {
                $data['users'] = authTestRows();
                if ($change === 'version') {
                    $data['users'][0]['auth_version'] = 2;
                } elseif ($change === 'inactive') {
                    $data['users'][0]['status'] = 'inactive';
                } else {
                    array_shift($data['users']);
                }
            });
            same(401, throws(HttpException::class, fn () => $service->requireUser())->status);
            same(null, $session->current);
        }
    } finally {
        removeTestDirectory($directory);
    }
});

test('AuthService logout requires identity and destroys the authenticated session', function (): void {
    $directory = tempDirectory();
    try {
        [$service, $session] = authServiceFixture($directory);
        same(401, throws(HttpException::class, fn () => $service->logout())->status);
        $service->login('student@example.test', 'Synthetic-test-pass-42', '192.0.2.1');
        $service->logout();
        same(null, $session->current);
        check($session->destroys > 0);
        same(401, throws(HttpException::class, fn () => $service->requireUser())->status);
    } finally {
        removeTestDirectory($directory);
    }
});

test('AuthService dummy hash and new password hashes use the same pinned algorithm and cost', function (): void {
    $dummy = password_get_info(AuthService::DUMMY_HASH);
    $created = password_get_info(AuthService::hashPassword('Synthetic-test-pass-42'));
    same(AuthService::HASH_ALGO, $dummy['algo']);
    same(AuthService::HASH_OPTIONS, $dummy['options']);
    same($dummy['algo'], $created['algo']);
    same($dummy['options'], $created['options']);
    check(!password_needs_rehash(AuthService::DUMMY_HASH, AuthService::HASH_ALGO, AuthService::HASH_OPTIONS));
});
