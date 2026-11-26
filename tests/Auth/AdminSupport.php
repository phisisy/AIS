<?php
declare(strict_types=1);

use App\Auth\Repositories\MockUserRepository;
use App\Auth\Services\AuthorizationService;
use App\Auth\Services\LoginRateLimiter;
use App\Auth\Services\UserService;
use App\Core\JsonStore;

require_once __DIR__ . '/HttpSupport.php';

/** Fictional accounts; every password is Synthetic-test-pass-42. */
function adminTestRows(): array
{
    $hash = authTestRows()[0]['password_hash'];
    $row = static fn (int $id, string $email, array $roles, string $status = 'active'): array
        => ['id' => $id, 'email' => $email, 'roles' => $roles, 'status' => $status, 'password_hash' => $hash, 'auth_version' => 1];
    return [
        $row(1, 'admin@example.test', ['admin']),
        $row(2, 'admin2@example.test', ['admin']),
        $row(3, 'student@example.test', ['student']),
        $row(4, 'faculty@example.test', ['faculty']),
        $row(5, 'old-admin@example.test', ['admin'], 'inactive'),
    ];
}

/** @return array{0: UserService, 1: MockUserRepository, 2: JsonStore} */
function adminFixture(string $directory, ?array $rows = null, ?callable $clock = null): array
{
    $store = new JsonStore($directory . '/users.json');
    $repository = new MockUserRepository($store);
    $repository->seed($rows ?? adminTestRows());
    $service = new UserService($repository, new AuthorizationService(),
        new LoginRateLimiter(new JsonStore($directory . '/attempts.json')), $clock ?? static fn (): int => 1_700_000_000);
    return [$service, $repository, $store];
}

/** Compact view of the audit log: "action:outcome" or "action:denied:reason". */
function auditSummary(App\Auth\Repositories\UserAdminRepositoryInterface $repository): array // Mock or MySQL repository (serverUsers())
{
    return array_map(static fn (array $entry): string => $entry['action'] . ':' . $entry['outcome']
        . ($entry['outcome'] === 'denied' ? ':' . $entry['changes']['reason'] : ''), $repository->auditEntries());
}

function auditCount(MockUserRepository $repository, string $outcome): int
{
    return count(array_filter($repository->auditEntries(), static fn (array $e): bool => $e['outcome'] === $outcome));
}

function activeAdminCount(MockUserRepository $repository): int
{
    return count(array_filter($repository->search('', 1, 100)['users'],
        static fn ($user): bool => $user->status === 'active' && in_array('admin', $user->roles, true)));
}
