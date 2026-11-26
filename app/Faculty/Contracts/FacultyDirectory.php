<?php
declare(strict_types=1);

namespace App\Faculty\Contracts;

/**
 * Team 5 to Teams 3/4. A mapping, NOT an authorization: access still goes through
 * SectionDirectory::isAssigned / sectionIdsForFaculty (A14). Throws on a corrupt store.
 */
interface FacultyDirectory
{
    /** null when the account has no profile; returns inactive profiles too. */
    public function findByUserId(int $userId): ?FacultySummary;
}
