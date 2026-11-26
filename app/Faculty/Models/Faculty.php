<?php
declare(strict_types=1);

namespace App\Faculty\Models;

use App\Faculty\Services\EmployeeNumberPolicy;
use RuntimeException;

/** Team 5 faculty profile. `id` is the faculty_id; `userId` is the Team 2 account id. */
final class Faculty
{
    public const STATUSES = ['active', 'inactive'];
    public const FIELDS = ['id', 'user_id', 'employee_number', 'first_name', 'last_name', 'department', 'status',
        'created_at', 'updated_at'];

    private function __construct(private readonly array $row)
    {
    }

    /** Free text of 1..$max chars, trimmed, valid UTF-8, no control/format characters. */
    public static function validText(mixed $value, int $max): bool
    {
        return is_string($value) && $value !== '' && trim($value) === $value && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') <= $max && preg_match('/[\p{Cc}\p{Cf}]/u', $value) !== 1;
    }

    /** Validates a stored row; corrupt data fails closed. */
    public static function fromArray(mixed $row, ?EmployeeNumberPolicy $numbers = null): self
    {
        if (!is_array($row) || array_keys($row) !== self::FIELDS
            || !is_int($row['id']) || $row['id'] < 1 || !is_int($row['user_id']) || $row['user_id'] < 1
            || !is_string($row['employee_number'])
            || ($numbers ?? new EmployeeNumberPolicy())->normalize($row['employee_number']) !== $row['employee_number']
            || !self::validText($row['first_name'], 70) || !self::validText($row['last_name'], 70)
            || !self::validText($row['department'], 100)
            || !in_array($row['status'], self::STATUSES, true)
            || !is_string($row['created_at']) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $row['created_at']) !== 1
            || !is_string($row['updated_at']) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $row['updated_at']) !== 1) {
            throw new RuntimeException('Invalid stored faculty.');
        }
        return new self($row);
    }

    public function id(): int { return $this->row['id']; }
    public function userId(): int { return $this->row['user_id']; }
    public function employeeNumber(): string { return $this->row['employee_number']; }
    public function status(): string { return $this->row['status']; }
    public function department(): string { return $this->row['department']; }
    public function displayName(): string { return $this->row['last_name'] . ', ' . $this->row['first_name']; }

    public function toArray(): array
    {
        return $this->row;
    }
}
