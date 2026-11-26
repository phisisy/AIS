<?php
declare(strict_types=1);

namespace App\Enrollment\Contracts;

/**
 * Port defined by Team 4, implemented by Team 5 (T-30). Production binding until then:
 * UnavailableFacultyIdentity. Callers refuse on null, inactive, an id mismatch or any Throwable.
 */
interface FacultyIdentityLookup
{
    /** null = unknown faculty id. */
    public function find(int $facultyId): ?FacultyIdentity;

    /**
     * @param list<int> $facultyIds
     * @return array<int, FacultyIdentity> keyed by faculty_id; unknown ids are omitted
     */
    public function findMany(array $facultyIds): array;
}
