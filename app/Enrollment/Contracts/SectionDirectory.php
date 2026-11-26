<?php
declare(strict_types=1);

namespace App\Enrollment\Contracts;

/**
 * Team 4 → Teams 3 and 5 (B-04: Team 4 owns instructor-to-section assignments). In-process only;
 * callers have already authenticated and authorized their own request.
 */
interface SectionDirectory
{
    public function findSection(int $sectionId): ?SectionSummary;

    /** @return list<int> student ids with approved enrollments; [] until T-22. */
    public function approvedStudentIds(int $sectionId): array;

    /** Access check: true only for a stored assignment AND a confirmed active faculty identity. */
    public function isAssigned(int $facultyId, int $sectionId): bool;

    /** Access list, ascending: [] unless the faculty identity is confirmed active. */
    public function sectionIdsForFaculty(int $facultyId): array;

    /** Display list in assignment order, including retained assignments of inactive faculty. @return list<int> */
    public function assignedFacultyIds(int $sectionId): array;
}
