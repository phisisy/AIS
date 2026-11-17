<?php
declare(strict_types=1);

namespace App\Auth\Repositories;

use App\Auth\Models\User;
use App\Core\HttpException;
use InvalidArgumentException;
use RuntimeException;

/** Working copy of the mock store used inside MockUserRepository::transaction(). */
final class MockUserTransaction implements UserTransactionInterface
{
    private const SECRET_KEY = '/pass|hash|token|secret|csrf|session|cookie/i';

    public function __construct(private array $rows, private array $audit)
    {
    }

    public function find(int $id): ?User
    {
        $index = $this->indexOf($id);
        return $index === null ? null : User::fromArray($this->rows[$index]);
    }

    public function findByEmail(string $email): ?User
    {
        $email = strtolower(trim($email));
        foreach ($this->rows as $row) {
            if ($row['email'] === $email) {
                return User::fromArray($row);
            }
        }
        return null;
    }

    public function insert(string $email, array $roles, string $status, string $passwordHash): User
    {
        $email = strtolower(trim($email));
        $this->assertEmailFree($email, null);
        $id = array_reduce($this->rows, static fn (int $max, array $row): int => max($max, $row['id']), 0) + 1;
        $row = ['id' => $id, 'email' => $email, 'roles' => $roles, 'status' => $status,
            'password_hash' => $passwordHash, 'auth_version' => 1];
        $user = User::fromArray($row);
        $this->rows[] = $row;
        return $user;
    }

    public function update(int $id, array $changes): User
    {
        $index = $this->indexOf($id) ?? throw new HttpException(404, 'User not found.');
        if (array_diff(array_keys($changes), ['email', 'roles', 'status', 'password_hash']) !== []) {
            throw new InvalidArgumentException('Unsupported user change.');
        }
        $row = array_replace($this->rows[$index], $changes);
        if (isset($changes['email'])) {
            $row['email'] = strtolower(trim($changes['email']));
            $this->assertEmailFree($row['email'], $id);
        }
        // A new password or a deactivation ends every existing session, so a stolen cookie
        // cannot come back to life when the account is reactivated.
        if (isset($changes['password_hash'])
            || (($changes['status'] ?? null) === 'inactive' && $this->rows[$index]['status'] !== 'inactive')) {
            $row['auth_version']++;
        }
        $user = User::fromArray($row);
        $this->rows[$index] = $row;
        return $user;
    }

    public function appendAudit(int $actorId, int $targetId, string $action, array $changes, string $at, string $outcome = 'success'): void
    {
        if (!in_array($outcome, ['success', 'denied'], true)
            || array_diff(array_keys($changes), self::AUDIT_KEYS) !== []) {
            throw new RuntimeException('Refusing an unsupported audit entry.');
        }
        self::assertNoSecretKeys($changes);
        $this->audit[] = ['id' => count($this->audit) + 1, 'at' => $at, 'actor_id' => $actorId,
            'target_id' => $targetId, 'action' => $action, 'outcome' => $outcome, 'changes' => $changes];
    }

    /** @return array{0: array, 1: array} */
    public function state(): array
    {
        return [$this->rows, $this->audit];
    }

    private static function assertNoSecretKeys(array $values): void
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key)) {
                throw new RuntimeException('Refusing to audit a secret field.');
            }
            if (is_array($value)) {
                self::assertNoSecretKeys($value);
            }
        }
    }

    private function assertEmailFree(string $email, ?int $exceptId): void
    {
        foreach ($this->rows as $row) {
            if ($row['email'] === $email && $row['id'] !== $exceptId) {
                throw new HttpException(409, 'Validation failed.', ['email' => 'This email is already in use.']);
            }
        }
    }

    private function indexOf(int $id): ?int
    {
        foreach ($this->rows as $index => $row) {
            if ($row['id'] === $id) {
                return $index;
            }
        }
        return null;
    }
}
