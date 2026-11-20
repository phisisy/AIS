<?php
declare(strict_types=1);

namespace App\Students\Contracts;

/**
 * B-07 source of truth for faculty access to student summaries. Implemented in T-31 from
 * Team 4's SectionDirectory (B-04: Team 4 owns instructor-to-section assignments, T-21)
 * and Team 4 approved enrollments (T-22). $facultyUserId is mapped to the Team 5 faculty_id
 * through FacultyDirectory::findByUserId (T-30). Implementations must return only verified,
 * official assignments; [] means "not visible".
 */
interface FacultyStudentVisibility
{
    /** @return list<array{section_id: int, section_code: string, enrollment_status: string}> */
    public function sharedSections(int $facultyUserId, int $studentId): array;
}
