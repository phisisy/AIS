<?php
declare(strict_types=1);

namespace App\Students\Contracts;

/**
 * B-07 source of truth for faculty access to student summaries. Implemented in T-31 from
 * Team 5 teaching assignments and Team 4 approved enrollments. Implementations must return
 * only verified, official assignments; [] means "not visible".
 */
interface FacultyStudentVisibility
{
    /** @return list<array{section_id: int, section_code: string, enrollment_status: string}> */
    public function sharedSections(int $facultyUserId, int $studentId): array;
}
