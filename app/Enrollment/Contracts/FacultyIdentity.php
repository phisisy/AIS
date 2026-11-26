<?php
declare(strict_types=1);

namespace App\Enrollment\Contracts;

use InvalidArgumentException;

/** Team 5 → Team 4 identity fact (T-21). Validates on construction so a bad Team 5 row fails closed. */
final class FacultyIdentity
{
    public function __construct(
        public readonly int $facultyId,
        public readonly string $displayName,
        public readonly bool $active,
    ) {
        if ($facultyId < 1 || $displayName === '' || trim($displayName) !== $displayName
            || !mb_check_encoding($displayName, 'UTF-8') || mb_strlen($displayName, 'UTF-8') > 150
            || preg_match('/[\p{Cc}\p{Cf}]/u', $displayName) !== 0) {
            throw new InvalidArgumentException('Invalid faculty identity.');
        }
    }
}
