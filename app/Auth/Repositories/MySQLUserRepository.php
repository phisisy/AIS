<?php
declare(strict_types=1);

namespace App\Auth\Repositories;

use App\Auth\Models\User;
use App\Core\HttpException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/** Auth adapter; the connection and schema are supplied by its caller. */
final class MySQLUserRepository implements UserAdminRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?User
    {
        return $this->read(fn (): ?User => (new MySQLUserTransaction($this->pdo, 0))->find($id));
    }

    public function findByEmail(string $email): ?User
    {
        return $this->read(fn (): ?User => (new MySQLUserTransaction($this->pdo, 0))->findByEmail($email));
    }

    public function search(string $emailContains, int $page, int $perPage): array
    {
        return $this->read(function () use ($emailContains, $page, $perPage): array {
            $needle = strtolower(trim($emailContains));
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle) . '%';
            $count = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE email LIKE ? ESCAPE '!'");
            $count->execute([$pattern]);
            $total = (int) $count->fetchColumn();
            $list = $this->pdo->prepare("SELECT id FROM users WHERE email LIKE ? ESCAPE '!' ORDER BY id LIMIT ? OFFSET ?");
            $list->bindValue(1, $pattern, PDO::PARAM_STR);
            $list->bindValue(2, $perPage, PDO::PARAM_INT);
            $list->bindValue(3, ($page - 1) * $perPage, PDO::PARAM_INT);
            $list->execute();
            $ids = $list->fetchAll(PDO::FETCH_COLUMN);
            $tx = new MySQLUserTransaction($this->pdo, 0);
            $users = [];
            foreach ($ids as $id) {
                $users[] = $tx->find((int) $id) ?? throw new RuntimeException('Invalid stored user.');
            }
            return ['users' => $users, 'total' => $total];
        });
    }

    public function transaction(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Nested store operations are not supported.');
        }
        try {
            $this->pdo->beginTransaction();
            $lock = $this->pdo->prepare('SELECT last_seq FROM repository_locks WHERE name = ? FOR UPDATE');
            $lock->execute(['auth']);
            $seq = $lock->fetchColumn();
            if ($seq === false) {
                throw new RuntimeException('Auth repository lock missing.');
            }
            $before = $this->activeAdmins();
            $result = $operation(new MySQLUserTransaction($this->pdo, (int) $seq));
            if ($before > 0 && $this->activeAdmins() === 0) {
                throw new HttpException(409, self::LAST_ADMIN_MESSAGE);
            }
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (PDOException) {
                    throw new RuntimeException('User repository unavailable.');
                }
            }
            if ($error instanceof PDOException) {
                throw new RuntimeException('User repository unavailable.');
            }
            throw $error;
        }
    }

    public function auditEntries(): array
    {
        return $this->read(function (): array {
            $statement = $this->pdo->prepare('SELECT id, at, actor_id, target_id, action, outcome, changes FROM audit_logs ORDER BY id');
            $statement->execute();
            $entries = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $changes = json_decode($row['changes'], true);
                if (!is_array($changes)) {
                    throw new RuntimeException('Invalid audit store.');
                }
                $entries[] = ['id' => (int) $row['id'], 'at' => str_replace(' ', 'T', $row['at']) . 'Z',
                    'actor_id' => (int) $row['actor_id'], 'target_id' => (int) $row['target_id'],
                    'action' => $row['action'], 'outcome' => $row['outcome'], 'changes' => $changes];
            }
            return $entries;
        });
    }

    private function activeAdmins(): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM users u JOIN user_roles r ON r.user_id = u.id WHERE u.status = 'active' AND r.role = 'admin'");
        $statement->execute();
        return (int) $statement->fetchColumn();
    }

    private function read(callable $operation): mixed
    {
        $started = !$this->pdo->inTransaction();
        try {
            if ($started) {
                $this->pdo->beginTransaction();
            }
            $result = $operation();
            if ($started) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (PDOException) {
                    throw new RuntimeException('User repository unavailable.');
                }
            }
            if ($error instanceof PDOException) {
                throw new RuntimeException('User repository unavailable.');
            }
            throw $error;
        }
    }
}
