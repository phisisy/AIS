<?php
declare(strict_types=1);

namespace App\Students\Models;

use RuntimeException;

/** Team 3 student profile. `id` is the student id; `userId` is the Team 2 account id. */
final class Student
{
    public const STATUSES = ['active', 'inactive', 'graduated', 'transferred', 'on_leave'];
    public const FIELDS = ['id', 'user_id', 'student_number', 'first_name', 'middle_name', 'last_name', 'program_code',
        'year_level', 'academic_status', 'admission_year', 'created_at', 'updated_at'];

    private function __construct(private readonly array $row)
    {
    }

    /** Validates a stored row; corrupt data fails closed. */
    public static function fromArray(mixed $row): self
    {
        if (!is_array($row) || array_keys($row) !== self::FIELDS
            || !is_int($row['id']) || $row['id'] < 1 || !is_int($row['user_id']) || $row['user_id'] < 1
            || !is_string($row['student_number']) || $row['student_number'] === ''
            || !is_string($row['first_name']) || !is_string($row['last_name'])
            || ($row['middle_name'] !== null && !is_string($row['middle_name']))
            || !is_string($row['program_code']) || !is_int($row['year_level']) || $row['year_level'] < 1
            || !in_array($row['academic_status'], self::STATUSES, true)
            || ($row['admission_year'] !== null && !is_int($row['admission_year']))
            || !is_string($row['created_at']) || !is_string($row['updated_at'])) {
            throw new RuntimeException('Invalid stored student.');
        }
        return new self($row);
    }

    public function id(): int { return $this->row['id']; }
    public function userId(): int { return $this->row['user_id']; }
    public function studentNumber(): string { return $this->row['student_number']; }
    public function programCode(): string { return $this->row['program_code']; }
    public function yearLevel(): int { return $this->row['year_level']; }

    /** Registrar/admin and the student's own view. */
    public function toArray(): array
    {
        return $this->row;
    }

    /** B-07 faculty view: approved summary fields only, plus the verified shared sections. */
    public function toFacultySummary(array $sections): array
    {
        $given = trim($this->row['first_name'] . ' ' . ($this->row['middle_name'] ?? ''));
        return ['id' => $this->row['id'], 'student_number' => $this->row['student_number'],
            'full_name' => $this->row['last_name'] . ', ' . $given, 'program_code' => $this->row['program_code'],
            'year_level' => $this->row['year_level'], 'sections' => $sections];
    }
}
