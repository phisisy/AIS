<?php
declare(strict_types=1);

namespace App\Students\Repositories;

use App\Core\HttpException;
use App\Students\Models\Student;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/** Student adapter; its caller supplies a configured connection and schema. */
final class MySQLStudentRepository implements StudentRepositoryInterface
{
    private const MUTABLE = ['first_name', 'middle_name', 'last_name', 'program_code', 'year_level', 'academic_status', 'admission_year'];
    private const TRACKED = ['program_code', 'year_level', 'academic_status'];
    private const COLUMNS = 'id, user_id, student_number, first_name, middle_name, last_name, program_code, year_level, academic_status, admission_year, created_at, updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?Student
    {
        return $this->read(fn (): ?Student => $this->find('id', $id));
    }

    public function findByUserId(int $userId): ?Student
    {
        return $this->read(fn (): ?Student => $this->find('user_id', $userId));
    }

    public function search(array $filters, int $page, int $perPage): array
    {
        return $this->read(function () use ($filters, $page, $perPage): array {
            $where = [];
            $params = [];
            if (isset($filters['program_code'])) {
                $where[] = 'program_code = ?';
                $params[] = $filters['program_code'];
            }
            if (isset($filters['academic_status'])) {
                $where[] = 'academic_status = ?';
                $params[] = $filters['academic_status'];
            }
            $needle = mb_strtolower(trim((string) ($filters['search'] ?? '')));
            if ($needle !== '') {
                $where[] = "(LOWER(student_number) LIKE ? ESCAPE '!' OR LOWER(first_name) LIKE ? ESCAPE '!' OR LOWER(last_name) LIKE ? ESCAPE '!')";
                $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle) . '%';
                array_push($params, $pattern, $pattern, $pattern);
            }
            $condition = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM students' . $condition);
            $count->execute($params);
            $total = (int) $count->fetchColumn();
            $list = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM students' . $condition . ' ORDER BY id LIMIT ? OFFSET ?');
            foreach ($params as $index => $value) {
                $list->bindValue($index + 1, $value);
            }
            $list->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
            $list->bindValue(count($params) + 2, ($page - 1) * $perPage, PDO::PARAM_INT);
            $list->execute();
            $students = array_map(self::student(...), $list->fetchAll(PDO::FETCH_ASSOC));
            return ['students' => $students, 'total' => $total];
        });
    }

    public function history(int $studentId, int $page, int $perPage): array
    {
        return $this->read(function () use ($studentId, $page, $perPage): array {
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM student_academic_history WHERE student_id = ?');
            $count->execute([$studentId]);
            $total = (int) $count->fetchColumn();
            $list = $this->pdo->prepare('SELECT seq, at, field, from_value, to_value, actor_id FROM student_academic_history WHERE student_id = ? ORDER BY seq LIMIT ? OFFSET ?');
            $list->bindValue(1, $studentId, PDO::PARAM_INT);
            $list->bindValue(2, $perPage, PDO::PARAM_INT);
            $list->bindValue(3, ($page - 1) * $perPage, PDO::PARAM_INT);
            $list->execute();
            $entries = [];
            foreach ($list->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!in_array($row['field'], self::TRACKED, true) || $row['to_value'] === null
                    || (int) $row['seq'] < 1 || (int) $row['actor_id'] < 0
                    || !preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $row['at'])) {
                    throw new RuntimeException('Invalid student history.');
                }
                $from = $row['from_value'];
                $to = $row['to_value'];
                if ($row['field'] === 'year_level') {
                    if (!preg_match('/\A[1-8]\z/', (string) $to) || ($from !== null && !preg_match('/\A[1-8]\z/', (string) $from))) {
                        throw new RuntimeException('Invalid student history.');
                    }
                    $from = $from === null ? null : (int) $from;
                    $to = (int) $to;
                } elseif ($row['field'] === 'program_code') {
                    if (!preg_match('/\A[A-Z0-9][A-Z0-9-]{0,19}\z/', (string) $to)
                        || ($from !== null && !preg_match('/\A[A-Z0-9][A-Z0-9-]{0,19}\z/', (string) $from))) {
                        throw new RuntimeException('Invalid student history.');
                    }
                } elseif (!in_array($to, Student::STATUSES, true)
                    || ($from !== null && !in_array($from, Student::STATUSES, true))) {
                    throw new RuntimeException('Invalid student history.');
                }
                $entries[] = ['seq' => (int) $row['seq'], 'at' => self::isoTime($row['at']), 'field' => $row['field'],
                    'from' => $from, 'to' => $to, 'actor_id' => (int) $row['actor_id']];
            }
            return ['entries' => $entries, 'total' => $total];
        });
    }

    public function create(array $fields, string $now, int $actorId = 0): Student
    {
        return $this->write(function () use ($fields, $now, $actorId): Student {
            $errors = [];
            $user = $this->pdo->prepare('SELECT id FROM students WHERE user_id = ?');
            $user->execute([$fields['user_id']]);
            if ($user->fetchColumn() !== false) {
                $errors['user_id'] = 'This account already has a student profile.';
            }
            $number = $this->pdo->prepare('SELECT id FROM students WHERE student_number = ?');
            $number->execute([$fields['student_number']]);
            if ($number->fetchColumn() !== false) {
                $errors['student_number'] = 'This student number is already in use.';
            }
            if ($errors !== []) {
                throw new HttpException(409, 'Validation failed.', $errors);
            }
            $row = ['id' => 1, 'user_id' => $fields['user_id'], 'student_number' => $fields['student_number'],
                'first_name' => $fields['first_name'], 'middle_name' => $fields['middle_name'], 'last_name' => $fields['last_name'],
                'program_code' => $fields['program_code'], 'year_level' => $fields['year_level'],
                'academic_status' => $fields['academic_status'], 'admission_year' => $fields['admission_year'],
                'created_at' => $now, 'updated_at' => $now];
            Student::fromArray($row);
            $sqlNow = self::sqlTime($now);
            $insert = $this->pdo->prepare('INSERT INTO students (user_id, student_number, first_name, middle_name, last_name, program_code, year_level, academic_status, admission_year, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([$row['user_id'], $row['student_number'], $row['first_name'], $row['middle_name'], $row['last_name'],
                $row['program_code'], $row['year_level'], $row['academic_status'], $row['admission_year'], $sqlNow, $sqlNow]);
            $id = (int) $this->pdo->lastInsertId();
            $this->appendHistory($id, array_fill_keys(self::TRACKED, null), $row, $now, $actorId);
            return $this->find('id', $id) ?? throw new RuntimeException('Invalid stored student.');
        });
    }

    public function update(int $id, array $changes, string $now, ?callable $check = null, int $actorId = 0): Student
    {
        if ($changes === [] || array_diff(array_keys($changes), self::MUTABLE) !== []) {
            throw new InvalidArgumentException('Unsupported student change.');
        }
        return $this->write(function () use ($id, $changes, $now, $check, $actorId): Student {
            $original = $this->find('id', $id);
            if ($original === null) {
                throw new HttpException(404, 'Student not found.');
            }
            $before = $original->toArray();
            $merged = array_replace($before, $changes, ['updated_at' => $now]);
            if ($check !== null) {
                $check($merged);
            }
            $updated = Student::fromArray($merged);
            $row = $updated->toArray();
            $statement = $this->pdo->prepare('UPDATE students SET first_name = ?, middle_name = ?, last_name = ?, program_code = ?, year_level = ?, academic_status = ?, admission_year = ?, updated_at = ? WHERE id = ?');
            $statement->execute([$row['first_name'], $row['middle_name'], $row['last_name'], $row['program_code'],
                $row['year_level'], $row['academic_status'], $row['admission_year'], self::sqlTime($now), $id]);
            $this->appendHistory($id, $before, $row, $now, $actorId);
            return $this->find('id', $id) ?? throw new RuntimeException('Invalid stored student.');
        });
    }

    private function appendHistory(int $id, array $before, array $after, string $now, int $actorId): void
    {
        $next = $this->pdo->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM student_academic_history WHERE student_id = ?');
        $next->execute([$id]);
        $seq = (int) $next->fetchColumn();
        $insert = $this->pdo->prepare('INSERT INTO student_academic_history (student_id, seq, at, field, from_value, to_value, actor_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach (self::TRACKED as $field) {
            if ($before[$field] !== $after[$field]) {
                $insert->execute([$id, $seq++, self::sqlTime($now), $field,
                    $before[$field] === null ? null : (string) $before[$field], (string) $after[$field], $actorId]);
            }
        }
    }

    private function find(string $column, int $value): ?Student
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM students WHERE ' . $column . ' = ?');
        $statement->execute([$value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::student($row);
    }

    private static function student(array $row): Student
    {
        foreach (['id', 'user_id', 'year_level'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['admission_year'] = $row['admission_year'] === null ? null : (int) $row['admission_year'];
        $row['created_at'] = self::isoTime($row['created_at']);
        $row['updated_at'] = self::isoTime($row['updated_at']);
        return Student::fromArray($row);
    }

    private static function sqlTime(string $time): string
    {
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $time)) {
            throw new RuntimeException('Invalid student timestamp.');
        }
        return substr($time, 0, 10) . ' ' . substr($time, 11, 8);
    }

    private static function isoTime(string $time): string
    {
        if (!preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $time)) {
            throw new RuntimeException('Invalid stored student.');
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
            $lock->execute(['students']);
            if ($lock->fetchColumn() === false) {
                throw new RuntimeException('Student repository lock missing.');
            }
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            $this->rollback();
            if ($error instanceof PDOException) {
                throw new RuntimeException('Student repository unavailable.');
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
                throw new RuntimeException('Student repository unavailable.');
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
                throw new RuntimeException('Student repository unavailable.');
            }
        }
    }
}
