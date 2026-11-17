<?php
declare(strict_types=1);

namespace App\Auth\Repositories;

use App\Auth\Models\User;
use App\Core\HttpException;
use App\Core\JsonStore;
use RuntimeException;

/** Development-only storage; no schema or production persistence assumptions. */
final class MockUserRepository implements UserAdminRepositoryInterface
{
    public function __construct(private readonly JsonStore $store)
    {
    }

    public function findById(int $id): ?User
    {
        foreach ($this->users($this->store->read()) as $user) {
            if ($user->id === $id) {
                return $user;
            }
        }
        return null;
    }

    public function findByEmail(string $email): ?User
    {
        $email = strtolower(trim($email));
        foreach ($this->users($this->store->read()) as $user) {
            if ($user->email === $email) {
                return $user;
            }
        }
        return null;
    }

    public function search(string $emailContains, int $page, int $perPage): array
    {
        $needle = strtolower(trim($emailContains));
        $matches = array_values(array_filter(
            $this->users($this->store->read()),
            static fn (User $user): bool => $needle === '' || str_contains($user->email, $needle)
        ));
        usort($matches, static fn (User $a, User $b): int => $a->id <=> $b->id);
        return ['users' => array_slice($matches, ($page - 1) * $perPage, $perPage), 'total' => count($matches)];
    }

    /**
     * One exclusive JsonStore transaction: users and audit live in the same file, so a change
     * and its audit entry commit together or not at all, and concurrent writers serialize.
     */
    public function transaction(callable $operation): mixed
    {
        return $this->store->transaction(function (array &$data) use ($operation): mixed {
            $before = $this->activeAdmins($this->users($data));
            $audit = $data['audit'] ?? [];
            if (!is_array($audit) || !array_is_list($audit)) {
                throw new RuntimeException('Invalid audit store.');
            }
            $transaction = new MockUserTransaction($data['users'] ?? [], $audit);
            $result = $operation($transaction);
            [$rows, $audit] = $transaction->state();
            // Invariant enforced here, under the lock, whatever the caller did.
            if ($before > 0 && $this->activeAdmins($this->users(['users' => $rows])) === 0) {
                throw new HttpException(409, self::LAST_ADMIN_MESSAGE);
            }
            $data['users'] = $rows;
            $data['audit'] = $audit;
            return $result;
        });
    }

    public function auditEntries(): array
    {
        $audit = $this->store->read()['audit'] ?? [];
        if (!is_array($audit) || !array_is_list($audit)) {
            throw new RuntimeException('Invalid audit store.');
        }
        return $audit;
    }

    private function activeAdmins(array $users): int
    {
        return count(array_filter($users, static fn (User $user): bool
            => $user->status === 'active' && in_array('admin', $user->roles, true)));
    }

    /** Used only by the explicit development CLI seeder. Never overwrites users. */
    public function seed(array $rows): void
    {
        $validated = $this->users(['users' => $rows]);
        $normalized = [];
        foreach ($validated as $index => $user) {
            $normalized[] = [
                'id' => $user->id,
                'email' => $user->email,
                'roles' => $user->roles,
                'status' => $user->status,
                'password_hash' => $rows[$index]['password_hash'],
                'auth_version' => $user->authVersion,
            ];
        }
        $this->store->transaction(function (array &$data) use ($normalized): void {
            if ($data !== []) {
                throw new RuntimeException('User store is already initialized.');
            }
            $data = ['users' => $normalized];
        });
    }

    /** Validate the whole snapshot before returning any identity. */
    private function users(array $data): array
    {
        if ($data === []) {
            return [];
        }
        if (!array_key_exists('users', $data) || !is_array($data['users']) || !array_is_list($data['users'])) {
            throw new RuntimeException('Invalid user store.');
        }
        $users = [];
        $ids = [];
        $emails = [];
        foreach ($data['users'] as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Invalid user store.');
            }
            $user = User::fromArray($row);
            if (isset($ids[$user->id]) || isset($emails[$user->email])) {
                throw new RuntimeException('Duplicate stored user.');
            }
            $ids[$user->id] = true;
            $emails[$user->email] = true;
            $users[] = $user;
        }
        return $users;
    }
}
