<?php
declare(strict_types=1);

namespace App\Faculty\Services;

use App\Auth\Models\User;
use App\Auth\Services\AuthorizationService;
use App\Core\HttpException;
use App\Enrollment\Contracts\SectionDirectory;
use App\Faculty\Contracts\FacultyDirectory;
use App\Faculty\Contracts\GradeReader;
use App\Faculty\Models\Grade;
use App\Faculty\Repositories\GradeRepositoryInterface;
use App\Students\Contracts\StudentDirectory;
use Closure;

/**
 * T-32 grade entry, submission, return, finalization, correction and the student view. The actor always comes from the session.
 * Atomic state rules live in the repository lock; this class does role, assignment, separation of duties (a), the scale and validation.
 * Staff reads include `remarks`; the student view (GradeReader) never does.
 */
final class GradeService
{
    private const STAFF = ['registrar', 'admin'];
    private Closure $clock;

    public function __construct(
        private readonly GradeRepositoryInterface $grades,
        private readonly GradeScale $scale,
        private readonly SectionDirectory $sections,
        private readonly FacultyDirectory $faculty,
        private readonly StudentDirectory $students,
        private readonly GradeReader $reader,
        private readonly AuthorizationService $authorization,
        ?callable $clock = null,
    ) {
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }

    /** Faculty view: every status, plus the approved students who still have no grade row. */
    public function classGrades(User $actor, int $sectionId): array
    {
        $this->requireAssigned($actor, $sectionId);
        $this->requireNotInSection($actor, $sectionId);
        return $this->view($sectionId);
    }

    public function enter(User $actor, int $sectionId, int $studentId, array $input): array
    {
        $this->authorization->requireRole($actor, ['faculty']);
        $this->requireNotSelf($actor, $studentId);
        $this->requireAssigned($actor, $sectionId);
        $this->requireScale();
        $this->allowFields($input, ['value', 'remarks']);
        $errors = [];
        if (!array_key_exists('value', $input)) {
            $errors['value'] = 'This field is required.';
        } elseif (!is_string($input['value']) || !$this->scale->allows($input['value'])) {
            $errors['value'] = 'Use a value from the grading scale.';
        }
        $remarks = $input['remarks'] ?? null;
        if ($remarks === '') {
            $remarks = null;
        }
        if ($remarks !== null && !Grade::validRemarks($remarks)) {
            $errors['remarks'] = 'Use at most 255 characters without control characters.';
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Validation failed.', $errors);
        }
        if (!in_array($studentId, $this->sections->approvedStudentIds($sectionId), true)) {
            throw new HttpException(404, 'Student not found in this class.');
        }
        $saved = $this->grades->saveDraft($sectionId, $studentId, $input['value'], $remarks, $this->now(), $actor->id);
        return ['grade' => $saved['grade']->toArray(), 'created' => $saved['created']];
    }

    public function submit(User $actor, int $sectionId): array
    {
        $this->authorization->requireRole($actor, ['faculty']);
        $this->requireAssigned($actor, $sectionId);
        $this->requireNotInSection($actor, $sectionId);
        $this->grades->submitSection($sectionId, $this->sections->approvedStudentIds($sectionId), $this->now(), $actor->id);
        return $this->view($sectionId);
    }

    public function sectionGrades(User $actor, int $sectionId): array
    {
        $this->authorization->requireRole($actor, self::STAFF);
        $this->requireNotInSection($actor, $sectionId);
        $this->sections->findSection($sectionId) ?? throw new HttpException(404, 'Section not found.');
        return $this->view($sectionId);
    }

    public function finalize(User $actor, int $sectionId): array
    {
        $this->authorization->requireRole($actor, self::STAFF);
        $this->requireNotInSection($actor, $sectionId);
        $this->sections->findSection($sectionId) ?? throw new HttpException(404, 'Section not found.');
        $this->requireNotAssigned($actor, $sectionId);
        // The pre-lock check above is a fast path; the same check re-runs under the locks (roster may change in between).
        $this->grades->finalizeSection($sectionId, $this->now(), $actor->id, $this->students->findByUserId($actor->id)?->id);
        return $this->view($sectionId);
    }

    public function returnToDraft(User $actor, int $sectionId, array $input): array
    {
        $this->authorization->requireRole($actor, self::STAFF);
        $this->requireNotInSection($actor, $sectionId);
        $this->allowFields($input, ['reason']);
        $this->requireReason($input);
        $this->sections->findSection($sectionId) ?? throw new HttpException(404, 'Section not found.');
        $this->requireNotAssigned($actor, $sectionId); // same separation of duties as finalize; (b) runs under the grades lock
        $this->grades->returnSection($sectionId, $input['reason'], $this->now(), $actor->id, $this->students->findByUserId($actor->id)?->id);
        return $this->view($sectionId);
    }

    public function correct(User $actor, int $gradeId, array $input): array
    {
        $this->authorization->requireRole($actor, self::STAFF);
        $this->requireScale();
        $this->allowFields($input, ['value', 'reason', 'expected_seq']);
        $errors = [];
        if (!array_key_exists('value', $input)) {
            $errors['value'] = 'This field is required.';
        } elseif (!is_string($input['value']) || !$this->scale->allows($input['value'])) {
            $errors['value'] = 'Use a value from the grading scale.';
        }
        if (!array_key_exists('reason', $input)) {
            $errors['reason'] = 'This field is required.';
        } elseif (!Grade::validReason($input['reason']) || mb_strlen($input['reason'], 'UTF-8') > 255) {
            $errors['reason'] = 'Use 1 to 255 characters without control characters.';
        }
        if (!array_key_exists('expected_seq', $input)) {
            $errors['expected_seq'] = 'This field is required.';
        } elseif (!is_int($input['expected_seq']) || $input['expected_seq'] < 1) {
            $errors['expected_seq'] = 'Use a positive whole number.';
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Validation failed.', $errors);
        }
        $grade = $this->grades->findGrade($gradeId) ?? throw new HttpException(404, 'Grade not found.');
        $this->requireNotSelf($actor, $grade->studentId());
        $this->requireNotAssigned($actor, $grade->sectionId());
        $corrected = $this->grades->correct($gradeId, $input['value'], $input['reason'], $input['expected_seq'], $this->now(), $actor->id);
        return ['grade' => $corrected->toArray()];
    }

    /** Own finalized grades only (value, grade status, enrollment status). A client-supplied student_id is refused. */
    public function mine(User $actor, array $query): array
    {
        $this->authorization->requireRole($actor, ['student']);
        $errors = [];
        foreach (array_diff(array_keys($query), ['term_id']) as $field) {
            $errors[(string) $field] = 'Unknown field.';
        }
        $termId = null;
        if (array_key_exists('term_id', $query)) {
            $termId = filter_var($query['term_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($termId === false) {
                $errors['term_id'] = 'Use a positive whole number.';
            }
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Validation failed.', $errors);
        }
        $student = $this->students->findByUserId($actor->id);
        $rows = $student === null ? [] : $this->reader->finalizedGradesForStudent($student->id);
        if ($termId !== null) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['term_id'] === $termId));
        }
        return ['grades' => $rows];
    }

    private function view(int $sectionId): array
    {
        $rows = $this->grades->forSection($sectionId);
        $graded = array_map(static fn (Grade $g): int => $g->studentId(), $rows);
        return ['grades' => array_map(static fn (Grade $g): array => $g->toArray(), $rows),
            'missing' => array_values(array_diff($this->sections->approvedStudentIds($sectionId), $graded))];
    }

    /** Active faculty profile AND an assignment; unknown sections answer 403 too, so ids cannot be enumerated. */
    private function requireAssigned(User $actor, int $sectionId): void
    {
        $this->authorization->requireRole($actor, ['faculty']);
        $profile = $this->faculty->findByUserId($actor->id);
        if ($profile === null || $profile->status !== 'active' || !$this->sections->isAssigned($profile->id, $sectionId)) {
            throw new HttpException(403, 'Access denied.');
        }
    }

    /** M1: an actor never acts on their own student record (account holding a student profile plus a staff/faculty role). */
    private function requireNotSelf(User $actor, int $studentId): void
    {
        $own = $this->students->findByUserId($actor->id);
        if ($own !== null && $own->id === $studentId) {
            throw new HttpException(403, 'Access denied.');
        }
    }

    /** M1: the actor's own student record must not be in the section (approved roster or any grade row). Checked before any lock. */
    private function requireNotInSection(User $actor, int $sectionId): void
    {
        $own = $this->students->findByUserId($actor->id);
        if ($own !== null && (in_array($own->id, $this->sections->approvedStudentIds($sectionId), true)
            || in_array($own->id, array_map(static fn (Grade $g): int => $g->studentId(), $this->grades->forSection($sectionId)), true))) {
            throw new HttpException(403, 'Access denied.');
        }
    }

    /** Separation of duties (a): anyone listed on the section (active or not) cannot finalize or correct it. (b) runs in the repository lock. */
    private function requireNotAssigned(User $actor, int $sectionId): void
    {
        $profile = $this->faculty->findByUserId($actor->id);
        if ($profile !== null && in_array($profile->id, $this->sections->assignedFacultyIds($sectionId), true)) {
            throw new HttpException(403, 'Access denied.');
        }
    }

    /** Fail closed while the scale is unconfirmed (B-05): before any read, nothing is written. */
    private function requireScale(): void
    {
        if (!$this->scale->isConfirmed()) {
            throw new HttpException(409, 'Validation failed.', ['grade_scale' => 'Grade entry is not available yet.']);
        }
    }

    private function requireReason(array $input): void
    {
        if (!array_key_exists('reason', $input)) {
            throw new HttpException(422, 'Validation failed.', ['reason' => 'This field is required.']);
        }
        if (!Grade::validReason($input['reason']) || mb_strlen($input['reason'], 'UTF-8') > 255) {
            throw new HttpException(422, 'Validation failed.', ['reason' => 'Use 1 to 255 characters without control characters.']);
        }
    }

    private function allowFields(array $input, array $allowed): void
    {
        $errors = [];
        foreach (array_diff(array_keys($input), $allowed) as $field) {
            $errors[(string) $field] = 'Unknown field.';
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Validation failed.', $errors);
        }
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', ($this->clock)());
    }
}
