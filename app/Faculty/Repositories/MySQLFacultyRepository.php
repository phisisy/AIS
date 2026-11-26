<?php
declare(strict_types=1);

namespace App\Faculty\Repositories;

use App\Core\HttpException;
use App\Faculty\Models\Faculty;
use App\Faculty\Services\EmployeeNumberPolicy;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/** Faculty adapter; its caller supplies a configured connection and schema. History seq comes from the 'faculty' lock row. */
final class MySQLFacultyRepository implements FacultyRepositoryInterface
{
    private const MUTABLE = ['first_name', 'last_name', 'department', 'status'];
    private const TRACKED = ['status', 'department'];
    private const COLUMNS = 'id, user_id, employee_number, first_name, last_name, department, status, created_at, updated_at';

    public function __construct(private readonly PDO $pdo, private readonly ?EmployeeNumberPolicy $numbers = null)
    {
    }

    public function findById(int $id): ?Faculty
    {
        return $this->read(fn (): ?Faculty => $this->find('id', $id));
    }

    public function findByUserId(int $userId): ?Faculty
    {
        return $this->read(fn (): ?Faculty => $this->find('user_id', $userId));
    }

    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_int($id) && $id >= 1)));
        if ($ids === []) {
            return [];
        }
        return $this->read(function () use ($ids): array {
            $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM faculty WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
            $statement->execute($ids);
            $found = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $faculty = $this->faculty($row);
                $found[$faculty->id()] = $faculty;
            }
            return $found;
        });
    }

    public function search(array $filters, int $page, int $perPage): array
    {
        return $this->read(function () use ($filters, $page, $perPage): array {
            $where = [];
            $params = [];
            if (isset($filters['status'])) {
                $where[] = 'status = ?';
                $params[] = $filters['status'];
            }
            $needle = mb_strtolower(trim((string) ($filters['search'] ?? '')));
            if ($needle !== '') {
                $where[] = "(LOWER(employee_number) LIKE ? ESCAPE '!' OR LOWER(first_name) LIKE ? ESCAPE '!' OR LOWER(last_name) LIKE ? ESCAPE '!')";
                $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle) . '%';
                array_push($params, $pattern, $pattern, $pattern);
            }
            $condition = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM faculty' . $condition);
            $count->execute($params);
            $total = (int) $count->fetchColumn();
            $list = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM faculty' . $condition . ' ORDER BY id LIMIT ? OFFSET ?');
            foreach ($params as $index => $value) {
                $list->bindValue($index + 1, $value);
            }
            $list->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
            $list->bindValue(count($params) + 2, ($page - 1) * $perPage, PDO::PARAM_INT);
            $list->execute();
            return ['faculty' => array_map($this->faculty(...), $list->fetchAll(PDO::FETCH_ASSOC)), 'total' => $total];
        });
    }

    public function create(array $fields, string $now, int $actorId): Faculty
    {
        return $this->write(function () use ($fields, $now, $actorId): Faculty {
            $errors = [];
            $user = $this->pdo->prepare('SELECT id FROM faculty WHERE user_id = ?');
            $user->execute([$fields['user_id']]);
            if ($user->fetchColumn() !== false) {
                $errors['user_id'] = 'This account already has a faculty profile.';
            }
            $number = $this->pdo->prepare('SELECT id FROM faculty WHERE employee_number = ?');
            $number->execute([$fields['employee_number']]);
            if ($number->fetchColumn() !== false) {
                $errors['employee_number'] = 'This employee number is already in use.';
            }
            if ($errors !== []) {
                throw new HttpException(409, 'Validation failed.', $errors);
            }
            $row = ['id' => 1, 'user_id' => $fields['user_id'], 'employee_number' => $fields['employee_number'],
                'first_name' => $fields['first_name'], 'last_name' => $fields['last_name'], 'department' => $fields['department'],
                'status' => $fields['status'], 'created_at' => $now, 'updated_at' => $now];
            Faculty::fromArray($row, $this->numbers);
            $sqlNow = self::sqlTime($now);
            $this->pdo->prepare('INSERT INTO faculty (user_id, employee_number, first_name, last_name, department, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$row['user_id'], $row['employee_number'], $row['first_name'], $row['last_name'], $row['department'],
                    $row['status'], $sqlNow, $sqlNow]);
            $id = (int) $this->pdo->lastInsertId();
            $this->appendHistory($id, array_fill_keys(self::TRACKED, null), $row, $now, $actorId);
            return $this->find('id', $id) ?? throw new RuntimeException('Invalid stored faculty.');
        });
    }

    public function update(int $id, array $changes, string $now, int $actorId): Faculty
    {
        if ($changes === [] || array_diff(array_keys($changes), self::MUTABLE) !== []) {
            throw new InvalidArgumentException('Unsupported faculty change.');
        }
        return $this->write(function () use ($id, $changes, $now, $actorId): Faculty {
            $original = $this->find('id', $id);
            if ($original === null) {
                throw new HttpException(404, 'Faculty profile not found.');
            }
            $before = $original->toArray();
            $row = Faculty::fromArray(array_replace($before, $changes, ['updated_at' => $now]), $this->numbers)->toArray();
            $this->pdo->prepare('UPDATE faculty SET first_name = ?, last_name = ?, department = ?, status = ?, updated_at = ? WHERE id = ?')
                ->execute([$row['first_name'], $row['last_name'], $row['department'], $row['status'], self::sqlTime($now), $id]);
            $this->appendHistory($id, $before, $row, $now, $actorId);
            return $this->find('id', $id) ?? throw new RuntimeException('Invalid stored faculty.');
        });
    }

    /** Runs inside write(): the 'faculty' lock row is held, so last_seq + 1 is gap-free. */
    private function appendHistory(int $id, array $before, array $after, string $now, int $actorId): void
    {
        $insert = $this->pdo->prepare('INSERT INTO faculty_history (seq, at, faculty_id, field, from_value, to_value, actor_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach (self::TRACKED as $field) {
            if ($before[$field] !== $after[$field]) {
                $next = $this->pdo->prepare('SELECT last_seq FROM repository_locks WHERE name = ?');
                $next->execute(['faculty']);
                $seq = (int) $next->fetchColumn() + 1;
                $this->pdo->prepare('UPDATE repository_locks SET last_seq = ? WHERE name = ?')->execute([$seq, 'faculty']);
                $insert->execute([$seq, self::sqlTime($now), $id, $field,
                    $before[$field] === null ? null : (string) $before[$field], (string) $after[$field], $actorId]);
            }
        }
    }

    private function find(string $column, int $value): ?Faculty
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM faculty WHERE ' . $column . ' = ?');
        $statement->execute([$value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->faculty($row);
    }

    private function faculty(array $row): Faculty
    {
        foreach (['id', 'user_id'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['created_at'] = self::isoTime($row['created_at']);
        $row['updated_at'] = self::isoTime($row['updated_at']);
        return Faculty::fromArray($row, $this->numbers);
    }

    private static function sqlTime(string $time): string
    {
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $time)) {
            throw new RuntimeException('Invalid faculty timestamp.');
        }
        return substr($time, 0, 10) . ' ' . substr($time, 11, 8);
    }

    private static function isoTime(string $time): string
    {
        // Same fix as 04a/04b (defect shown online 2026-11-26): shape alone admits zero dates; require an exact calendar round trip.
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $time, new \DateTimeZone('UTC'));
        if (!preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $time)
            || $parsed === false || $parsed->format('Y-m-d H:i:s') !== $time || $parsed->format('Y') === '0000') {
            throw new RuntimeException('Invalid stored faculty.');
        }
        return str_replace(' ', 'T', $time) . 'Z';
    }

    private function write(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Nested store operations are not supported.');
        }
        try {
            $this->pdo->beginTransaction();
            $lock = $this->pdo->prepare('SELECT last_seq FROM repository_locks WHERE name = ? FOR UPDATE');
            $lock->execute(['faculty']);
            if ($lock->fetchColumn() === false) {
                throw new RuntimeException('Faculty repository lock missing.');
            }
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            $this->rollback();
            if ($error instanceof PDOException) {
                throw new RuntimeException('Faculty repository unavailable.');
            }
            throw $error;
        }
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
            if ($started) {
                $this->rollback();
            }
            if ($error instanceof PDOException) {
                throw new RuntimeException('Faculty repository unavailable.');
            }
            throw $error;
        }
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            try {
                $this->pdo->rollBack();
            } catch (PDOException) {
                throw new RuntimeException('Faculty repository unavailable.');
            }
        }
    }
}
