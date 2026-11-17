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

    public function create(array $fields, string $now): Student
    {
        return $this->store->transaction(function (array &$data) use ($fields, $now): Student {
            $students = $this->students($data);
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
            return $student;
        });
    }

    public function update(int $id, array $changes, string $now, ?callable $check = null): Student
    {
        if ($changes === [] || array_diff(array_keys($changes), self::MUTABLE) !== []) {
            throw new InvalidArgumentException('Unsupported student change.');
        }
        return $this->store->transaction(function (array &$data) use ($id, $changes, $now, $check): Student {
            $this->students($data);
            foreach ($data['students'] as $index => $row) {
                if ($row['id'] === $id) {
                    $merged = array_replace($row, $changes, ['updated_at' => $now]);
                    if ($check !== null) {
                        $check($merged);
                    }
                    $updated = Student::fromArray($merged);
                    $data['students'][$index] = $updated->toArray();
                    return $updated;
                }
            }
            throw new HttpException(404, 'Student not found.');
        });
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
