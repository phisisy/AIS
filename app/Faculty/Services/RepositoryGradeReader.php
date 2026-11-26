<?php
declare(strict_types=1);

namespace App\Faculty\Services;

use App\Enrollment\Repositories\CatalogRepositoryInterface;
use App\Faculty\Contracts\GradeReader;
use App\Faculty\Repositories\GradeRepositoryInterface;
use App\Students\Contracts\AcademicSummarySource;
use RuntimeException;

/**
 * Student-facing finalized grades (`/api/grades/me`, T-11a summary). Never exposes remarks, ids or history.
 * enrollment_status is the status of the student's latest enrollment row for the section (a finalized grade survives a drop).
 * Errors propagate (the T-11a record then shows "unavailable").
 */
final class RepositoryGradeReader implements GradeReader, AcademicSummarySource
{
    public function __construct(private readonly GradeRepositoryInterface $grades, private readonly CatalogRepositoryInterface $catalog)
    {
    }

    public function finalizedGradesForStudent(int $studentId): array
    {
        $out = [];
        foreach ($this->grades->finalizedForStudent($studentId) as $grade) {
            $section = $this->catalog->findSection($grade->sectionId()) ?? throw new RuntimeException('Grade reader unavailable.');
            $total = $this->catalog->searchEnrollments(['student_id' => $studentId, 'section_id' => $grade->sectionId()], 1, 1)['total'];
            $latest = $total > 0 ? $this->catalog->searchEnrollments(['student_id' => $studentId, 'section_id' => $grade->sectionId()], $total, 1)['enrollments'] : [];
            if ($latest === []) {
                throw new RuntimeException('Grade reader unavailable.');
            }
            $out[] = ['section_id' => $section->id(), 'term_id' => $section->termId(), 'course_code' => $section->courseCode(),
                'section_code' => $section->sectionCode(), 'value' => $grade->value(), 'grade_status' => $grade->status(),
                'enrollment_status' => $latest[0]->status()];
        }
        return $out;
    }

    /**
     * `finalized` plus `finalized_<enrollment status>` counts, e.g. finalized_dropped. Null when the student has no
     * finalized grade, so the record shows {available:false} (T-32 plan §247, owner decision 2026-11-26). Failures throw.
     */
    public function countsFor(int $studentId): ?array
    {
        $rows = $this->finalizedGradesForStudent($studentId);
        if ($rows === []) {
            return null;
        }
        $counts = ['finalized' => count($rows)];
        foreach ($rows as $row) {
            $key = 'finalized_' . $row['enrollment_status'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        return $counts;
    }
}
