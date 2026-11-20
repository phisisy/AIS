<?php
declare(strict_types=1);

namespace App\Enrollment\Contracts;

/**
 * Verifies nobody, so every new assignment is refused and no assignment grants access (owner, 2026-11-20).
 * The production binding until T-30. Mock lookups exist only under tests/.
 */
final class UnavailableFacultyIdentity implements FacultyIdentityLookup
{
    public function find(int $facultyId): ?FacultyIdentity
    {
        return null;
    }

    public function findMany(array $facultyIds): array
    {
        return [];
    }
}
