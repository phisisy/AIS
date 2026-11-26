<?php
declare(strict_types=1);

namespace App\Students\Services;

use RuntimeException;

/**
 * Institutional student-number format from configuration (STUDENT_NUMBER_PATTERN): a PCRE body
 * without delimiters or anchors. Whatever the pattern, values are also limited to 1–32 chars of
 * [A-Z0-9-/] after trimming and uppercasing. An invalid pattern fails at construction.
 */
final class StudentNumberPolicy
{
    public const DEFAULT_PATTERN = '[A-Z0-9][A-Z0-9-]{2,19}';
    private string $regex;

    public function __construct(?string $pattern = null)
    {
        $pattern ??= self::DEFAULT_PATTERN;
        if ($pattern === '' || strlen($pattern) > 200 || str_contains($pattern, '~') || str_contains($pattern, "\0")) {
            throw new RuntimeException('Invalid student number pattern.');
        }
        $this->regex = '~\A(?:' . $pattern . ')\z~';
        if (@preg_match($this->regex, '') === false) {
            throw new RuntimeException('Invalid student number pattern.');
        }
    }

    /** Returns the normalized number, or null if it does not satisfy the policy. */
    public function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $number = strtoupper(trim($value));
        return preg_match('~\A[A-Z0-9/-]{1,32}\z~', $number) === 1 && preg_match($this->regex, $number) === 1 ? $number : null;
    }
}
