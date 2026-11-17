<?php
declare(strict_types=1);

namespace App\Students\Contracts;

/** Default until a verified class-assignment source exists (T-31): faculty see no student records. */
final class DenyAllFacultyVisibility implements FacultyStudentVisibility
{
    public function sharedSections(int $facultyUserId, int $studentId): array
    {
        return [];
    }
}
