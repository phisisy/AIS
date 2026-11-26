<?php
declare(strict_types=1);

namespace App\Faculty\Contracts;

/** Team 5 read-only port. Student-facing shape only: never contains remarks. Throws on a corrupt store. */
interface GradeReader
{
    /**
     * Finalized grades only, ordered by section_id.
     * @return list<array{section_id:int, term_id:int, course_code:string, section_code:string, value:string, grade_status:string, enrollment_status:string}>
     */
    public function finalizedGradesForStudent(int $studentId): array;
}
