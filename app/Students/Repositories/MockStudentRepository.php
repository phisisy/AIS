<?php
declare(strict_types=1);

namespace App\Students\Repositories;

use App\Core\HttpException;
use App\Core\JsonStore;
use App\Students\Models\Student;
use InvalidArgumentException;
use RuntimeException;

/** Development storage until Team 6's schema is final. One file, one exclusive lock per write. */
final class MockStudentRepository implements StudentRepositoryInterface
{
    private const MUTABLE = ['first_name', 'middle_name', 'last_name', 'program_code', 'year_level', 'academic_status', 'admission_year'];
    /** Academic fields whose changes are kept as history (T-11a). Name/admission corrections are not academic history. */
    private const TRACKED = ['program_code', 'year_level', 'academic_status'];

    public function __construct(private readonly JsonStore $store)
    {
    }

    public function findById(int $id): ?Student
    {
        foreach ($this->students($this->store->read()) as $student) {
            if ($student->id() === $id) {
                return $student;
            }
        }
        return null;
    }

    public function findByUserId(int $userId): ?Student
    {
        foreach ($this->students($this->store->read()) as $student) {
            if ($student->userId() === $userId) {
                return $student;
            }
        }
        return null;
    }

    public function search(array $filters, int $page, int $perPage): array
    {
        $needle = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $matches = array_values(array_filter($this->students($this->store->read()), static function (Student $s) use ($filters, $needle): bool {
            $row = $s->toArray();
            if (isset($filters['program_code']) && $row['program_code'] !== $filters['program_code']) {
                return false;
            }
            if (isset($filters['academic_status']) && $row['academic_status'] !== $filters['academic_status']) {
                return false;
            }
            return $needle === '' || str_contains(mb_strtolower($row['student_number']), $needle)
                || str_contains(mb_strtolower($row['first_name']), $needle) || str_contains(mb_strtolower($row['last_name']), $needle);
        }));
        usort($matches, static fn (Student $a, Student $b): int => $a->id() <=> $b->id());
        return ['students' => array_slice($matches, ($page - 1) * $perPage, $perPage), 'total' => count($matches)];
    }

    public function history(int $studentId, int $page, int $perPage): array
    {
        $entries = $this->historyMap($this->store->read())[(string) $studentId] ?? [];
        return ['entries' => array_slice($entries, ($page - 1) * $perPage, $perPage), 'total' => count($entries)];
    }

    public function create(array $fields, string $now, int $actorId = 0): Student
    {
        return $this->store->transaction(function (array &$data) use ($fields, $now, $actorId): Student {
            $students = $this->students($data);
            $history = $this->historyMap($data);
            $errors = [];
            foreach ($students as $existing) {
                if ($existing->userId() === $fields['user_id']) {
                    $errors['user_id'] = 'This account already has a student profile.';
                }
                if ($existing->studentNumber() === $fields['student_number']) {
                    $errors['student_number'] = 'This student number is already in use.';
                }
            }
            if ($errors !== []) {
                throw new HttpException(409, 'Validation failed.', $errors);
            }
            $id = array_reduce($students, static fn (int $max, Student $s): int => max($max, $s->id()), 0) + 1;
            $row = ['id' => $id, 'user_id' => $fields['user_id'], 'student_number' => $fields['student_number'],
                'first_name' => $fields['first_name'], 'middle_name' => $fields['middle_name'], 'last_name' => $fields['last_name'],
                'program_code' => $fields['program_code'], 'year_level' => $fields['year_level'],
                'academic_status' => $fields['academic_status'], 'admission_year' => $fields['admission_year'],
                'created_at' => $now, 'updated_at' => $now];
            $student = Student::fromArray($row);
            $data['students'] = array_merge($data['students'] ?? [], [$row]);
            // Same transaction: the profile and its initial history entries commit together.
            $empty = array_fill_keys(self::TRACKED, null);
            $data['history'] = self::appendHistory($history, $id, $empty, $row, $now, $actorId);
            return $student;
        });
    }

    public function update(int $id, array $changes, string $now, ?callable $check = null, int $actorId = 0): Student
    {
        if ($changes === [] || array_diff(array_keys($changes), self::MUTABLE) !== []) {
            throw new InvalidArgumentException('Unsupported student change.');
        }
        return $this->store->transaction(function (array &$data) use ($id, $changes, $now, $check, $actorId): Student {
            $this->students($data);
            $history = $this->historyMap($data);
            foreach ($data['students'] as $index => $row) {
                if ($row['id'] === $id) {
                    $merged = array_replace($row, $changes, ['updated_at' => $now]);
                    if ($check !== null) {
                        $check($merged);
                    }
                    $updated = Student::fromArray($merged);
                    $data['students'][$index] = $updated->toArray();
                    // Same transaction: a tracked change and its history entry commit together.
                    $data['history'] = self::appendHistory($history, $id, $row, $merged, $now, $actorId);
                    return $updated;
                }
            }
            throw new HttpException(404, 'Student not found.');
        });
    }

    /** One entry per tracked field whose value actually changed; seq is per student, gap-free. */
    private static function appendHistory(array $history, int $id, array $before, array $after, string $now, int $actorId): array
    {
        $key = (string) $id;
        $entries = $history[$key] ?? [];
        foreach (self::TRACKED as $field) {
            if ($before[$field] !== $after[$field]) {
                // Never write what historyMap() would reject on read.
                if (!self::validHistoryValue($field, $before[$field]) || !self::validHistoryValue($field, $after[$field])) {
                    throw new RuntimeException('Invalid student history.');
                }
                $entries[] = ['seq' => count($entries) + 1, 'at' => $now, 'field' => $field,
                    'from' => $before[$field], 'to' => $after[$field], 'actor_id' => $actorId];
            }
        }
        if ($entries !== []) {
            $history[$key] = $entries;
        }
        return $history;
    }

    /** Typed check per tracked field; null only as the "before" of a creation entry. */
    private static function validHistoryValue(string $field, mixed $value): bool
    {
        return $value === null || match ($field) {
            'program_code' => is_string($value) && preg_match('/\A[A-Z0-9][A-Z0-9-]{0,19}\z/', $value) === 1,
            'year_level' => is_int($value) && $value >= 1 && $value <= 8,
            'academic_status' => in_array($value, Student::STATUSES, true),
        };
    }

    /** Validates the history map (corruption fails closed). A store without history has none yet. */
    private function historyMap(array $data): array
    {
        $history = $data['history'] ?? [];
        if (!is_array($history) || array_is_list($history) && $history !== []) {
            throw new RuntimeException('Invalid student history.');
        }
        foreach ($history as $key => $entries) {
            if (!preg_match('/\A[1-9][0-9]*\z/', (string) $key) || !is_array($entries) || !array_is_list($entries)) {
                throw new RuntimeException('Invalid student history.');
            }
            foreach ($entries as $index => $entry) {
                if (!is_array($entry) || array_keys($entry) !== ['seq', 'at', 'field', 'from', 'to', 'actor_id']
                    || $entry['seq'] !== $index + 1 || !is_string($entry['at'])
                    || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $entry['at']) || !in_array($entry['field'], self::TRACKED, true)
                    || !is_int($entry['actor_id']) || $entry['actor_id'] < 0
                    || !self::validHistoryValue($entry['field'], $entry['from']) || !self::validHistoryValue($entry['field'], $entry['to'])) {
                    throw new RuntimeException('Invalid student history.');
                }
            }
        }
        return $history;
    }

    /** Validates the whole snapshot (corruption fails closed). @return list<Student> */
    private function students(array $data): array
    {
        if ($data === []) {
            return [];
        }
        if (!isset($data['students']) || !is_array($data['students']) || !array_is_list($data['students'])) {
            throw new RuntimeException('Invalid student store.');
        }
        return array_map(static fn (mixed $row): Student => Student::fromArray($row), $data['students']);
    }
}
