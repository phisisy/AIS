<?php
declare(strict_types=1);

namespace App\Faculty\Models;

use RuntimeException;

/**
 * Team 5 grade row, one per (section, student). Status is draft|submitted|finalized; a correction is a history action, not a status.
 * `seq` is the seq of the latest grade_history row of the grade (the optimistic-concurrency token for corrections);
 * it is supplied by the repository and is not part of the stored row.
 */
final class Grade
{
    public const STATUSES = ['draft', 'submitted', 'finalized'];
    public const FIELDS = ['id', 'section_id', 'student_id', 'value', 'remarks', 'status', 'created_at', 'updated_at'];
    private const TIME = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/';

    private function __construct(private readonly array $row, private readonly int $seq)
    {
    }

    /** Free text of 0..$max chars, valid UTF-8, no control/format characters (empty is allowed here). */
    public static function validRemarks(mixed $value): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && mb_strlen($value, 'UTF-8') <= 255
            && preg_match('/[\p{Cc}\p{Cf}]/u', $value) !== 1;
    }

    /** Reasons are 1..255 chars, trimmed, valid UTF-8, no control/format characters. */
    public static function validReason(mixed $value): bool
    {
        return is_string($value) && $value !== '' && trim($value) === $value && self::validRemarks($value);
    }

    /** Validates a stored row; corrupt data fails closed. */
    public static function fromArray(mixed $row, int $seq = 0): self
    {
        if (!is_array($row) || array_keys($row) !== self::FIELDS
            || !is_int($row['id']) || $row['id'] < 1 || !is_int($row['section_id']) || $row['section_id'] < 1
            || !is_int($row['student_id']) || $row['student_id'] < 1
            || !is_string($row['value']) || !mb_check_encoding($row['value'], 'UTF-8')
            || mb_strlen($row['value'], 'UTF-8') < 1 || mb_strlen($row['value'], 'UTF-8') > 8
            || ($row['remarks'] !== null && (!self::validRemarks($row['remarks']) || $row['remarks'] === ''))
            || !in_array($row['status'], self::STATUSES, true)
            || !is_string($row['created_at']) || preg_match(self::TIME, $row['created_at']) !== 1
            || !is_string($row['updated_at']) || preg_match(self::TIME, $row['updated_at']) !== 1
            || $seq < 0) {
            throw new RuntimeException('Invalid stored grade.');
        }
        return new self($row, $seq);
    }

    public function id(): int { return $this->row['id']; }
    public function sectionId(): int { return $this->row['section_id']; }
    public function studentId(): int { return $this->row['student_id']; }
    public function value(): string { return $this->row['value']; }
    public function status(): string { return $this->row['status']; }
    public function seq(): int { return $this->seq; }
    public function row(): array { return $this->row; }

    /** Staff view only (includes remarks and the correction token `seq`). Students never receive this shape. */
    public function toArray(): array
    {
        return ['id' => $this->row['id'], 'section_id' => $this->row['section_id'], 'student_id' => $this->row['student_id'],
            'value' => $this->row['value'], 'remarks' => $this->row['remarks'], 'status' => $this->row['status'],
            'updated_at' => $this->row['updated_at'], 'seq' => $this->seq];
    }
}
