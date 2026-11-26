<?php
declare(strict_types=1);

use App\Enrollment\Contracts\FacultyIdentity;
use App\Enrollment\Contracts\FacultyIdentityLookup;

/** Test-only (A4): returns the real answer, then runs $afterFind, e.g. deactivates the profile right after the lookup. */
final class DeactivatingFacultyIdentity implements FacultyIdentityLookup
{
    public function __construct(private readonly FacultyIdentityLookup $inner, private readonly Closure $afterFind)
    {
    }

    public function find(int $facultyId): ?FacultyIdentity
    {
        $identity = $this->inner->find($facultyId);
        ($this->afterFind)($facultyId);
        return $identity;
    }

    public function findMany(array $facultyIds): array
    {
        return $this->inner->findMany($facultyIds);
    }
}
