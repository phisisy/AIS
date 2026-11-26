<?php
declare(strict_types=1);

namespace App\Auth\Repositories;

use App\Auth\Models\User;
use App\Core\HttpException;
use DateTimeImmutable;
use DateTimeZone;
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
                $entries[] = self::auditRow($row, $changes);
            }
            return $entries;
        });
    }

    public function searchAudit(array $filters, int $page, int $perPage): array
    {
        return $this->read(function () use ($filters, $page, $perPage): array {
            $where = [];
            $values = [];
            foreach (['action' => 'action', 'outcome' => 'outcome', 'actor_id' => 'actor_id', 'target_id' => 'target_id'] as $key => $column) {
                if (isset($filters[$key])) {
                    $where[] = "$column = ?";
                    $values[] = $filters[$key];
                }
            }
            foreach (['from' => 'at >= ?', 'to' => 'at <= ?'] as $key => $clause) {
                if (isset($filters[$key])) {
                    $where[] = $clause;
                    $values[] = str_replace(['T', 'Z'], [' ', ''], $filters[$key]);
                }
            }
            $sql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM audit_logs' . $sql);
            $count->execute($values);
            $total = (int) $count->fetchColumn();
            $list = $this->pdo->prepare('SELECT id, at, actor_id, target_id, action, outcome, changes FROM audit_logs'
                . $sql . ' ORDER BY id DESC LIMIT ? OFFSET ?');
            foreach ($values as $i => $value) {
                $list->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $list->bindValue(count($values) + 1, $perPage, PDO::PARAM_INT);
            $list->bindValue(count($values) + 2, ($page - 1) * $perPage, PDO::PARAM_INT);
            $list->execute();
            $entries = [];
            foreach ($list->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $changes = json_decode($row['changes'], true);
                if (!is_array($changes)) {
                    throw new RuntimeException('Invalid audit store.');
                }
                $entries[] = self::auditRow($row, $changes);
            }
            return ['entries' => $entries, 'total' => $total];
        });
    }

    private static function auditRow(array $row, array $changes): array
    {
        return ['id' => (int) $row['id'], 'at' => self::isoTime((string) $row['at']),
            'actor_id' => (int) $row['actor_id'], 'target_id' => (int) $row['target_id'],
            'action' => $row['action'], 'outcome' => $row['outcome'], 'changes' => $changes];
    }

    /** SR-1: exact round trip of a stored DATETIME; anything else is a corrupt store (no previous, no value leaked). */
    private static function isoTime(string $time): string
    {
        $parsed = preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $time) === 1
            ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $time, new DateTimeZone('UTC')) : false;
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $time || $parsed->format('Y') === '0000') {
            throw new RuntimeException('Invalid audit store.');
        }
        return str_replace(' ', 'T', $time) . 'Z';
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
