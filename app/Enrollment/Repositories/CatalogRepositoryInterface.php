<?php
declare(strict_types=1);

namespace App\Enrollment\Repositories;

use App\Enrollment\Models\Enrollment;
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

    /*
     * T-22 enrollments. Every writer runs in the same `enrollment` lock/transaction as the section writers.
     * Errors are HttpException(status, 'Validation failed.', [field => message]) unless stated. Timestamps are
     * ISO 'Y-m-d\TH:i:s\Z'. decided_at/dropped_at = max($now, latest stored timestamp of the row) so the
     * DB CHECK ordering can never be violated by clock skew. Every transition appends one history row
     * (seq = per-enrollment count + 1) in the same transaction. No idempotent replays.
     */

    /**
     * Fixed check order, first failure wins: section exists (422 section_id) -> term enrollment_open (409 section_id)
     * -> no pending/approved row for (student, section) (409 section_id) -> section approved_count < capacity
     * (409 section_id) -> no clash with the student's pending+approved rows in the same term (409 section_id,
     * message names "COURSE-SEC (section N)"). Then INSERT status 'pending' + history 'requested' (reason null).
     */
    public function requestEnrollment(int $studentId, int $sectionId, string $now, int $actorId): Enrollment;

    /**
     * $status is 'approved' or 'rejected' (anything else: InvalidArgumentException). Order: row exists (404
     * 'Enrollment not found.') -> status = pending (409 status) -> approve only: approved_count < capacity
     * (409 status), no clash with the student's APPROVED rows in the same term (409 status). Then UPDATE status,
     * decided_at, decided_by, reason; approve also does sections.approved_count + 1 (sections.updated_at untouched);
     * append history 'approved'/'rejected' with actor and reason. Rejection requires a non-null reason (the
     * service guarantees it; the repository must refuse a null reason on 'rejected' with InvalidArgumentException).
     */
    public function decideEnrollment(int $id, string $status, ?string $reason, string $now, int $actorId): Enrollment;

    /**
     * Order: row exists AND student_id matches (404 'Enrollment not found.', never a 403) -> status pending or
     * approved (409 status) -> the section's term enrollment_open (409 status; students cannot drop after the term
     * closes, N-1). Then UPDATE status 'dropped', dropped_at; if the row was approved, approved_count - 1;
     * decided_at/decided_by/reason are kept; append history 'dropped' (reason null).
     */
    public function dropEnrollment(int $id, int $studentId, string $now, int $actorId): Enrollment;

    public function findEnrollment(int $id): ?Enrollment;

    /**
     * $filters (all optional, ANDed): status, term_id (the section's term), section_id, student_id.
     * Ordered by id ascending. @return array{enrollments: list<Enrollment>, total: int}
     */
    public function searchEnrollments(array $filters, int $page, int $perPage): array;

    /** Student ids with an 'approved' row in the section; ascending, distinct. Unknown section: []. @return list<int> */
    public function approvedStudentIds(int $sectionId): array;

    /**
     * T-32 lock coordination (decisions §1c). Takes the `enrollment` lock exactly like every catalog writer,
     * re-reads the section's approved student ids UNDER that lock, and runs $fn(list<int> $approvedIds) while the lock
     * is still held; the lock is released when $fn returns or throws (its result or exception propagates, nothing in
     * the catalog is written). Only the grade repository's finalize calls this, and $fn may take the `grades` lock
     * but must never call this repository again (fixed order: enrollment, then grades; nothing takes grades first).
     * Mock: the catalog store lock. MySQL: BEGIN; SELECT ... FROM repository_locks WHERE name='enrollment' FOR UPDATE;
     * roster SELECT; $fn on the SAME PDO transaction (the grade repository joins it, it never begins its own); COMMIT.
     * @return mixed whatever $fn returns
     */
    public function withEnrollmentLock(int $sectionId, callable $fn): mixed;

    /*
     * OQ-3 (no new method): updateSection(), when $changes contains 'meetings', must in the same lock/transaction
     * refuse with HttpException(409, 'Validation failed.', ['meetings' => ...]) and write nothing if the new meetings
     * would make any student's approved enrollments in this section's term overlap (this section vs the student's
     * other approved sections). Same overlap predicate as the instructor check; sections without meetings never clash.
     * It runs after the existing instructor-conflict check.
     */
}
