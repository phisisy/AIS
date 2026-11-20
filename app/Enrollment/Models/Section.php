<?php
declare(strict_types=1);

namespace App\Enrollment\Models;

use RuntimeException;

final class Section
{
    public const FIELDS = ['id', 'term_id', 'course_code', 'course_title', 'units', 'section_code',
        'capacity', 'approved_count', 'delivery_mode', 'meetings', 'created_at', 'updated_at'];
    /** Only an explicit tba/online_async designation allows a section without meetings (owner decision 3). */
    public const DELIVERY_MODES = ['scheduled', 'tba', 'online_async'];
    public const DAYS = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];

    private function __construct(private readonly array $row)
    {
    }

    /** Validates stored fields, including the capacity and meeting invariants. */
    public static function fromArray(mixed $row): self
    {
        if (!is_array($row) || array_keys($row) !== self::FIELDS
            || !is_int($row['id']) || $row['id'] < 1 || !is_int($row['term_id']) || $row['term_id'] < 1
            || !is_string($row['course_code']) || preg_match('/\A[A-Z0-9][A-Z0-9-]{1,19}\z/', $row['course_code']) !== 1
            || !self::validText($row['course_title'], 150)
            || !is_int($row['units']) || $row['units'] < 1 || $row['units'] > 12
            || !is_string($row['section_code']) || preg_match('/\A[A-Z0-9][A-Z0-9-]{0,9}\z/', $row['section_code']) !== 1
            || !is_int($row['capacity']) || $row['capacity'] < 1 || $row['capacity'] > 1000
            || !is_int($row['approved_count']) || $row['approved_count'] < 0 || $row['approved_count'] > $row['capacity']
            || !in_array($row['delivery_mode'], self::DELIVERY_MODES, true)
            || !self::validMeetings($row['meetings'], $row['delivery_mode'] === 'scheduled' ? 1 : 0)
            || !is_string($row['created_at']) || !is_string($row['updated_at'])) {
            throw new RuntimeException('Invalid stored section.');
        }
        return new self($row);
    }

    private static function validText(mixed $value, int $max): bool
    {
        return is_string($value) && $value !== '' && trim($value) === $value
            && mb_check_encoding($value, 'UTF-8') && mb_strlen($value, 'UTF-8') <= $max
            && preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 0;
    }

    private static function validMeetings(mixed $meetings, int $min): bool
    {
        if (!is_array($meetings) || !array_is_list($meetings) || count($meetings) < $min || count($meetings) > 14) {
            return false;
        }
        foreach ($meetings as $i => $meeting) {
            if (!is_array($meeting) || array_keys($meeting) !== ['day', 'start', 'end', 'room']
                || !in_array($meeting['day'], self::DAYS, true)
                || !is_string($meeting['start']) || preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $meeting['start']) !== 1
                || !is_string($meeting['end']) || preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $meeting['end']) !== 1
                || $meeting['start'] >= $meeting['end'] || !self::validText($meeting['room'], 30)) {
                return false;
            }
            for ($j = 0; $j < $i; $j++) {
                $other = $meetings[$j];
                if ($meeting['day'] === $other['day'] && $meeting['start'] < $other['end'] && $other['start'] < $meeting['end']) {
                    return false;
                }
            }
        }
        return true;
    }

    public function id(): int { return $this->row['id']; }
    public function termId(): int { return $this->row['term_id']; }
    public function courseCode(): string { return $this->row['course_code']; }
    public function sectionCode(): string { return $this->row['section_code']; }
    public function capacity(): int { return $this->row['capacity']; }
    public function approvedCount(): int { return $this->row['approved_count']; }
    public function deliveryMode(): string { return $this->row['delivery_mode']; }

    public function toArray(): array
    {
        return array_slice($this->row, 0, 8, true)
            + ['remaining' => $this->capacity() - $this->approvedCount()]
            + array_slice($this->row, 8, null, true);
    }
}
