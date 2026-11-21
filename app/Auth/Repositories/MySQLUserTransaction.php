<?php
declare(strict_types=1);

namespace App\Auth\Repositories;

use App\Auth\Models\User;
use App\Core\HttpException;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Operations on the connection already locked by MySQLUserRepository. */
final class MySQLUserTransaction implements UserTransactionInterface
{
    private const SECRET_KEY = '/pass|hash|token|secret|csrf|session|cookie/i';

    public function __construct(private readonly PDO $pdo, private int $lastSeq)
    {
    }

    public function find(int $id): ?User
    {
        $row = $this->row('SELECT id, email, status, password_hash, auth_version FROM users WHERE id = ?', [$id]);
        return $row === null ? null : User::fromArray($this->withRoles($row));
    }

    public function findByEmail(string $email): ?User
    {
        $email = strtolower(trim($email));
        $row = $this->row('SELECT id, email, status, password_hash, auth_version FROM users WHERE email = ?', [$email]);
        return $row === null ? null : User::fromArray($this->withRoles($row));
    }

    public function insert(string $email, array $roles, string $status, string $passwordHash): User
    {
        $email = strtolower(trim($email));
        $this->assertEmailFree($email, null);
        User::fromArray(['id' => 1, 'email' => $email, 'roles' => $roles, 'status' => $status,
            'password_hash' => $passwordHash, 'auth_version' => 1]);
        $statement = $this->pdo->prepare('INSERT INTO users (email, status, password_hash, auth_version) VALUES (?, ?, ?, 1)');
        $statement->execute([$email, $status, $passwordHash]);
        $id = (int) $this->pdo->lastInsertId();
        $this->replaceRoles($id, $roles);
        return $this->find($id) ?? throw new RuntimeException('Invalid stored user.');
    }

    public function update(int $id, array $changes): User
    {
        $stored = $this->row('SELECT id, email, status, password_hash, auth_version FROM users WHERE id = ?', [$id]);
        if ($stored === null) {
            throw new HttpException(404, 'User not found.');
        }
        if (array_diff(array_keys($changes), ['email', 'roles', 'status', 'password_hash']) !== []) {
            throw new InvalidArgumentException('Unsupported user change.');
        }
        $original = $this->withRoles($stored);
        User::fromArray($original);
        $row = array_replace($original, $changes);
        if (isset($changes['email'])) {
            $row['email'] = strtolower(trim($changes['email']));
            $this->assertEmailFree($row['email'], $id);
        }
        if (isset($changes['password_hash'])
            || (($changes['status'] ?? null) === 'inactive' && $original['status'] !== 'inactive')) {
            $row['auth_version']++;
        }
        User::fromArray($row);
        $statement = $this->pdo->prepare('UPDATE users SET email = ?, status = ?, password_hash = ?, auth_version = ? WHERE id = ?');
        $statement->execute([$row['email'], $row['status'], $row['password_hash'], $row['auth_version'], $id]);
        if (array_key_exists('roles', $changes)) {
            $this->replaceRoles($id, $row['roles']);
        }
        return $this->find($id) ?? throw new RuntimeException('Invalid stored user.');
    }

    public function appendAudit(int $actorId, int $targetId, string $action, array $changes, string $at, string $outcome = 'success'): void
    {
        if (!in_array($outcome, ['success', 'denied'], true)
            || array_diff(array_keys($changes), self::AUDIT_KEYS) !== []) {
            throw new RuntimeException('Refusing an unsupported audit entry.');
        }
        self::assertNoSecretKeys($changes);
        // The service supplies UTC seconds. DATETIME cannot preserve arbitrary strings.
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $at)) {
            throw new RuntimeException('Invalid audit timestamp.');
        }
        $json = json_encode($changes, JSON_THROW_ON_ERROR);
        $next = $this->lastSeq + 1;
        $statement = $this->pdo->prepare('UPDATE repository_locks SET last_seq = ? WHERE name = ?');
        $statement->execute([$next, 'auth']);
        $statement = $this->pdo->prepare('INSERT INTO audit_logs (id, at, actor_id, target_id, action, outcome, changes) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$next, str_replace(['T', 'Z'], [' ', ''], $at), $actorId, $targetId, $action, $outcome, $json]);
        $this->lastSeq = $next;
    }

    private function assertEmailFree(string $email, ?int $exceptId): void
    {
        $row = $this->row('SELECT id FROM users WHERE email = ?', [$email]);
        if ($row !== null && (int) $row['id'] !== $exceptId) {
            throw new HttpException(409, 'Validation failed.', ['email' => 'This email is already in use.']);
        }
    }

    private function withRoles(array $row): array
    {
        $statement = $this->pdo->prepare('SELECT role FROM user_roles WHERE user_id = ? ORDER BY role');
        $statement->execute([(int) $row['id']]);
        return ['id' => (int) $row['id'], 'email' => $row['email'], 'roles' => $statement->fetchAll(PDO::FETCH_COLUMN),
            'status' => $row['status'], 'password_hash' => $row['password_hash'], 'auth_version' => (int) $row['auth_version']];
    }

    private function replaceRoles(int $id, array $roles): void
    {
        $statement = $this->pdo->prepare('DELETE FROM user_roles WHERE user_id = ?');
        $statement->execute([$id]);
        $statement = $this->pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (?, ?)');
        foreach ($roles as $role) {
            $statement->execute([$id, $role]);
        }
    }

    private function row(string $sql, array $parameters): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
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
}
