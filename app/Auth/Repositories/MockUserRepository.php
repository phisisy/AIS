<?php
declare(strict_types=1);

namespace App\Auth\Repositories;

use App\Auth\Models\User;
use App\Core\JsonStore;
use RuntimeException;

/** Development-only storage; no schema or production persistence assumptions. */
final class MockUserRepository implements UserRepositoryInterface
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
