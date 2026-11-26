<?php
declare(strict_types=1);

namespace App\Enrollment\Models;

use RuntimeException;

/** One enrollment request. term_id is derived from the section and never stored. */
final class Enrollment
{
    public const FIELDS = ['id', 'student_id', 'section_id', 'status', 'requested_at', 'decided_at', 'decided_by', 'dropped_at', 'reason'];
    public const STATUSES = ['pending', 'approved', 'rejected', 'dropped'];
    public const TIMESTAMP = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/';

    private function __construct(private readonly array $row, private readonly int $termId)
    {
    }

    /** Validates a stored row, including the decision/status pairing; corrupt data fails closed. */
    public static function fromArray(mixed $row, int $termId): self
    {
        if (!is_array($row) || array_keys($row) !== self::FIELDS || $termId < 1
            || !is_int($row['id']) || $row['id'] < 1 || !is_int($row['student_id']) || $row['student_id'] < 1
            || !is_int($row['section_id']) || $row['section_id'] < 1
            || !in_array($row['status'], self::STATUSES, true)
            || !self::timestamp($row['requested_at'])
            || ($row['decided_at'] !== null && !self::timestamp($row['decided_at']))
            || ($row['dropped_at'] !== null && !self::timestamp($row['dropped_at']))
            || ($row['decided_by'] !== null && (!is_int($row['decided_by']) || $row['decided_by'] < 1))
            || ($row['reason'] !== null && !self::validReason($row['reason']))
            || ($row['decided_at'] !== null && $row['decided_at'] < $row['requested_at'])
            || ($row['dropped_at'] !== null && $row['dropped_at'] < $row['requested_at'])) {
            throw new RuntimeException('Invalid stored enrollment.');
        }
        $decided = $row['decided_at'] !== null && $row['decided_by'] !== null;
        $undecided = $row['decided_at'] === null && $row['decided_by'] === null;
        $valid = match ($row['status']) {
            'pending' => $undecided && $row['dropped_at'] === null,
            'approved' => $decided && $row['dropped_at'] === null,
            'rejected' => $decided && $row['dropped_at'] === null && $row['reason'] !== null,
            'dropped' => $row['dropped_at'] !== null && ($decided || $undecided),
        };
        if (!$valid) {
            throw new RuntimeException('Invalid stored enrollment.');
        }
        return new self($row, $termId);
    }

    public static function validReason(mixed $value): bool
    {
        return is_string($value) && $value !== '' && trim($value) === $value
            && mb_check_encoding($value, 'UTF-8') && mb_strlen($value, 'UTF-8') <= 255
            && preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 0;
    }

    private static function timestamp(mixed $value): bool
    {
        return is_string($value) && preg_match(self::TIMESTAMP, $value) === 1;
    }

    public function id(): int { return $this->row['id']; }
    public function studentId(): int { return $this->row['student_id']; }
    public function sectionId(): int { return $this->row['section_id']; }
    public function termId(): int { return $this->termId; }
    public function status(): string { return $this->row['status']; }

    /** The stored columns only (no term_id); used by repositories to rewrite the row. */
    public function row(): array { return $this->row; }

    /** $staff: decided_by is shown to registrar/admin only. */
    public function toArray(bool $staff = false): array
    {
        $row = array_slice($this->row, 0, 3, true) + ['term_id' => $this->termId] + array_slice($this->row, 3, null, true);
        if (!$staff) {
            unset($row['decided_by']);
        }
        return $row;
    }
}
