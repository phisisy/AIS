<?php
declare(strict_types=1);

namespace App\Faculty\Repositories;

use App\Faculty\Models\Grade;

/**
 * T-32 grades. Every writer runs in the `grades` lock/transaction (Mock: one JSON store; MySQL: repository_locks 'grades'
 * FOR UPDATE) and appends exactly one grade_history row per changed grade in the same transaction, seq = gap-free
 * repository_locks('grades').last_seq + 1. Errors are HttpException(status, message, [key => value]); a failure writes nothing.
 * Timestamps are ISO 'Y-m-d\TH:i:s\Z'. Never takes any lock other than `grades`, EXCEPT saveDraft and finalizeSection (enrollment first, then grades).
 *
 * History actions: entered, updated, submitted, returned, finalized, corrected. `old_value`/`new_value` are set for
 * entered (null -> v), updated and corrected only, NULL otherwise; `reason` is non-null exactly for corrected and returned.
 * L3: `old_remarks`/`new_remarks` (staff-only, never shown to students; NULL = no remarks, and on pre-V0009 rows) follow the same
 * actions: entered (old NULL, new = remarks), updated (old = remarks before, new = remarks after; a remarks-only change is still one
 * 'updated' row), corrected (both = the unchanged current remarks); NULL for submitted, returned and finalized.
 * Section SoD lookups use grade_history.section_id.
 */
interface GradeRepositoryInterface
{
    public function findGrade(int $id): ?Grade;

    /** @return list<Grade> every status, ordered by student_id; each carries the latest history seq. */
    public function forSection(int $sectionId): array;

    /** @return list<Grade> status finalized only, ordered by section_id. */
    public function finalizedForStudent(int $studentId): array;

    /**
     * L2 lock path: CatalogRepositoryInterface::withEnrollmentLock($sectionId, ...) wraps the grades lock (enrollment THEN grades, one
     * transaction, same as finalizeSection). First, under both locks: $studentId not in the re-read approved roster -> 404
     * 'Student not found in this class.', nothing written. Then:
     * No row -> insert draft + history 'entered' (created = true). Row draft -> update value/remarks/updated_at + 'updated'.
     * Row submitted or finalized -> 409 ['status' => ...], nothing written.
     * @return array{grade: Grade, created: bool}
     */
    public function saveDraft(int $sectionId, int $studentId, string $value, ?string $remarks, string $now, int $actorId): array;

    /**
     * $rosterIds = the currently approved student ids the caller read (accepted TOCTOU, only finalize re-reads under lock).
     * In the lock: every roster student must have a row of any status, else 409 ['grades_missing' => list<int>];
     * at least one roster student's row must be draft, else 409 ['status' => ...]. Those draft rows become submitted
     * (history 'submitted'); drafts of non-roster students are untouched.
     * @param list<int> $rosterIds @return list<Grade> the rows that changed
     */
    public function submitSection(int $sectionId, array $rosterIds, string $now, int $actorId): array;

    /**
     * In the grades lock, in this order: (1) SoD (b): $actorId has an entered/updated/submitted history row in the section -> 403, nothing written;
     * (1b) L1: $ownStudentId (the actor's own student id, or null) has a grade row in the section -> 403, nothing written;
     * (2) none submitted -> 409 ['status' => ...]; then every submitted row becomes draft (history 'returned' with $reason). @return list<Grade>
     */
    public function returnSection(int $sectionId, string $reason, string $now, int $actorId, ?int $ownStudentId = null): array;

    /**
     * Lock order enrollment THEN grades, one transaction: CatalogRepositoryInterface::withEnrollmentLock($sectionId, ...) wraps the
     * grades lock, and the approved roster is the one re-read under both locks. In the lock, in this order:
     * (1) SoD (b): $actorId has an entered/updated/submitted history row in the section -> 403;
     * (1b) M1: $ownStudentId (the actor's own student id, or null) is on the locked roster or has a grade row in the section -> 403, nothing written;
     * (2) any roster student without a row, or with a draft row -> 409 ['grades_missing' => list<int> student ids];
     * (3) no submitted row in the section -> 409 ['status' => ...];
     * then EVERY submitted row of the section (a dropped student's included) becomes finalized (history 'finalized'); finalized rows are untouched.
     * @return list<Grade> the rows that changed
     */
    public function finalizeSection(int $sectionId, string $now, int $actorId, ?int $ownStudentId = null): array;

    /**
     * In the lock, in this order: row exists (404) -> status finalized (409 ['status']) -> SoD (b) (403) ->
     * latest history seq of the row !== $expectedSeq (409 ['stale_grade']) -> $value !== current value (409 ['value']).
     * Then value/updated_at updated, history 'corrected' (old, new, reason, actor).
     */
    public function correct(int $id, string $value, string $reason, int $expectedSeq, string $now, int $actorId): Grade;
}
