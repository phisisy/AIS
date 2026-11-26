<?php
declare(strict_types=1);

namespace App\Enrollment\Services;

use App\Enrollment\Contracts\FacultyIdentityLookup;
use App\Enrollment\Contracts\SectionDirectory;
use App\Enrollment\Contracts\SectionSummary;
use App\Enrollment\Repositories\CatalogRepositoryInterface;

final class RepositorySectionDirectory implements SectionDirectory
{
    public function __construct(
        private readonly CatalogRepositoryInterface $catalog,
        private readonly FacultyIdentityLookup $identities,
    ) {
    }

    public function findSection(int $sectionId): ?SectionSummary
    {
        $section = $this->catalog->findSection($sectionId);
        return $section === null ? null : new SectionSummary($section->id(), $section->termId(),
            $section->courseCode(), $section->sectionCode());
    }

    public function approvedStudentIds(int $sectionId): array
    {
        return $this->catalog->approvedStudentIds($sectionId);
    }

    public function isAssigned(int $facultyId, int $sectionId): bool
    {
        if (!$this->confirmedActive($facultyId)) {
            return false;
        }
        return in_array($facultyId, $this->catalog->assignedFacultyIds([$sectionId])[$sectionId] ?? [], true);
    }

    public function sectionIdsForFaculty(int $facultyId): array
    {
        return $this->confirmedActive($facultyId) ? $this->catalog->sectionIdsForFaculty($facultyId) : [];
    }

    public function assignedFacultyIds(int $sectionId): array
    {
        return $this->catalog->assignedFacultyIds([$sectionId])[$sectionId] ?? [];
    }

    private function confirmedActive(int $facultyId): bool
    {
        if ($facultyId < 1) {
            return false;
        }
        try {
            $identity = $this->identities->find($facultyId);
            return $identity !== null && $identity->facultyId === $facultyId && $identity->active;
        } catch (\Throwable) {
            return false;
        }
    }
}
