<?php
declare(strict_types=1);

namespace App\Enrollment\Models;

use RuntimeException;

final class Term
{
    public const FIELDS = ['id', 'code', 'name', 'start_date', 'end_date', 'is_current', 'enrollment_open', 'created_at', 'updated_at'];

    private function __construct(private readonly array $row)
    {
    }

    /** Validates a stored row; corrupt data fails closed. */
    public static function fromArray(mixed $row): self
    {
        if (!is_array($row) || array_keys($row) !== self::FIELDS
            || !is_int($row['id']) || $row['id'] < 1
            || !is_string($row['code']) || preg_match('/\A[A-Z0-9][A-Z0-9-]{1,19}\z/', $row['code']) !== 1
            || !self::validName($row['name'])
            || !self::validDate($row['start_date']) || !self::validDate($row['end_date'])
            || $row['start_date'] >= $row['end_date']
            || !is_bool($row['is_current']) || !is_bool($row['enrollment_open'])
            || !is_string($row['created_at']) || !is_string($row['updated_at'])) {
            throw new RuntimeException('Invalid stored term.');
        }
        return new self($row);
    }

    private static function validName(mixed $value): bool
    {
        return is_string($value) && $value !== '' && trim($value) === $value
            && mb_check_encoding($value, 'UTF-8') && mb_strlen($value, 'UTF-8') <= 100
            && preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 0;
    }

    private static function validDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }

    public function id(): int { return $this->row['id']; }
    public function code(): string { return $this->row['code']; }
    public function isCurrent(): bool { return $this->row['is_current']; }
    public function startDate(): string { return $this->row['start_date']; }
    public function endDate(): string { return $this->row['end_date']; }

    public function toArray(): array
    {
        return $this->row;
    }
}
