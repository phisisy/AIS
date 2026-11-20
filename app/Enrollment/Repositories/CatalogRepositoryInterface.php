<?php
declare(strict_types=1);

namespace App\Enrollment\Repositories;

use App\Enrollment\Models\Section;
use App\Enrollment\Models\Term;

interface CatalogRepositoryInterface
{
    /** @return list<Term> ordered by start_date, then id. */
    public function terms(): array;
    public function findTerm(int $id): ?Term;

    /** Assigns id/timestamps; checks code uniqueness and clears other current terms in one lock. */
    public function createTerm(array $fields, string $now): Term;

    /** Mutable fields only; 404 if missing. $check runs on the merged row inside the lock before writing. */
    public function updateTerm(int $id, array $changes, string $now, ?callable $check = null): Term;

    public function findSection(int $id): ?Section;

    /** $filters: term_id, course_code, search. @return array{sections: list<Section>, total: int} */
    public function searchSections(array $filters, int $page, int $perPage): array;

    /** approved_count = 0; term existence and the unique term/course/section triple are checked in the lock. */
    public function createSection(array $fields, string $now): Section;

    /** Mutable fields only; 404 if missing. $check runs on the merged row inside the lock before writing. */
    public function updateSection(int $id, array $changes, string $now, ?callable $check = null): Section;

    /** @param list<int> $sectionIds @return array<int, list<int>> One read; ids in assignment order. */
    public function assignedFacultyIds(array $sectionIds): array;

    /** @return list<int> ascending */
    public function sectionIdsForFaculty(int $facultyId): array;

    /** @return array{section:Section,faculty_ids:list<int>} */
    public function assignInstructor(int $sectionId, int $facultyId, string $now, int $actorId): array;

    /** @return array{section:Section,faculty_ids:list<int>} */
    public function unassignInstructor(int $sectionId, int $facultyId, string $now, int $actorId): array;
}
