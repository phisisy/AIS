<?php
declare(strict_types=1);

namespace App\Faculty\Services;

use App\Enrollment\Contracts\SectionDirectory;
use App\Faculty\Contracts\FacultyDirectory;
use App\Students\Contracts\FacultyStudentVisibility;

/**
 * B-07 / T-31: a student is visible to a faculty member iff approved in a section that faculty is
 * currently assigned to, and the section belongs to the current term (is_current). Read live on every call (nothing cached). Any lookup error propagates;
 * StudentService treats an exception as "not visible", so unknown state denies.
 */
final class RosterFacultyVisibility implements FacultyStudentVisibility
{
    /** @param \Closure(): list<\App\Enrollment\Models\Term> $terms the catalog's terms() read (live, uncached) */
    public function __construct(private readonly FacultyDirectory $faculty, private readonly SectionDirectory $sections,
        private readonly \Closure $terms)
    {
    }

    public function sharedSections(int $facultyUserId, int $studentId): array
    {
        $profile = $this->faculty->findByUserId($facultyUserId);
        if ($profile === null || $profile->status !== 'active') {
            return [];
        }
        // Owner decision 2026-11-25: current term only. Zero or several current terms (ambiguous) deny; a read error propagates.
        $currentIds = [];
        foreach (($this->terms)() as $term) {
            $term->isCurrent() && $currentIds[] = $term->id();
        }
        if (count($currentIds) !== 1) {
            return [];
        }
        $shared = [];
        // sectionIdsForFaculty is [] unless the faculty identity (profile + account + role) is confirmed active.
        foreach ($this->sections->sectionIdsForFaculty($profile->id) as $sectionId) {
            $section = in_array($studentId, $this->sections->approvedStudentIds($sectionId), true)
                ? $this->sections->findSection($sectionId) : null;
            if ($section !== null && $section->termId === $currentIds[0]) {
                $shared[] = ['section_id' => $section->id, 'section_code' => $section->courseCode . '-' . $section->sectionCode,
                    'enrollment_status' => 'approved'];
            }
        }
        return $shared;
    }
}
