<?php
declare(strict_types=1);

namespace App\Faculty\Contracts;

/** Read-only Team 5 fact for Teams 3/4 (B-11), in-process only. status is the profile status. */
final class FacultySummary
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $employeeNumber,
        public readonly string $displayName,
        public readonly string $status,
    ) {
    }
}
