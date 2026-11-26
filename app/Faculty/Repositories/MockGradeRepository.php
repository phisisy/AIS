<?php
declare(strict_types=1);

namespace App\Faculty\Repositories;

use App\Core\HttpException;
use App\Core\JsonStore;
use App\Enrollment\Repositories\CatalogRepositoryInterface;
use App\Faculty\Models\Grade;
use App\Faculty\Services\GradeScale;
use RuntimeException;

/** Development storage: {grades:[...], history:[...]} in one file, one exclusive lock; history is one global, gap-free, replay-checked list. */
final class MockGradeRepository implements GradeRepositoryInterface
{
    private const ACTIONS = ['entered', 'updated', 'submitted', 'returned', 'finalized', 'corrected'];
    private const HISTORY_KEYS = ['seq', 'at', 'action', 'grade_id', 'section_id', 'student_id', 'old_value', 'new_value', 'old_remarks', 'new_remarks', 'reason', 'actor_id'];
    private const TIME = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/';

    public function __construct(private readonly JsonStore $store, private readonly CatalogRepositoryInterface $catalog)
    {
    }

    public function findGrade(int $id): ?Grade
    {
        foreach ($this->snapshot($this->store->read())[0] as $grade) {
            if ($grade->id() === $id) {
                return $grade;
            }
        }
        return null;
    }

    public function forSection(int $sectionId): array
    {
        $rows = array_values(array_filter($this->snapshot($this->store->read())[0], static fn (Grade $g): bool => $g->sectionId() === $sectionId));
        usort($rows, static fn (Grade $a, Grade $b): int => $a->studentId() <=> $b->studentId());
        return $rows;
    }

    public function finalizedForStudent(int $studentId): array
    {
        $rows = array_values(array_filter($this->snapshot($this->store->read())[0],
            static fn (Grade $g): bool => $g->studentId() === $studentId && $g->status() === 'finalized'));
        usort($rows, static fn (Grade $a, Grade $b): int => $a->sectionId() <=> $b->sectionId());
        return $rows;
    }

    public function saveDraft(int $sectionId, int $studentId, string $value, ?string $remarks, string $now, int $actorId): array
    {
        self::requireDemoValue($value);
        // Same fixed lock order as finalizeSection: enrollment (roster re-read under it), then grades. L2: a student who left the roster is refused.
        return $this->catalog->withEnrollmentLock($sectionId, fn (array $roster): array => $this->store->transaction(
            function (array &$data) use ($sectionId, $studentId, $value, $remarks, $now, $actorId, $roster): array {
            if (!in_array($studentId, $roster, true)) {
                throw new HttpException(404, 'Student not found in this class.');
            }
            [$grades] = $this->snapshot($data);
            $index = null;
            foreach ($data['grades'] ?? [] as $i => $row) {
                if ($row['section_id'] === $sectionId && $row['student_id'] === $studentId) {
                    $index = $i;
                }
            }
            $created = $index === null;
            if ($created) {
                $id = array_reduce($grades, static fn (int $max, Grade $g): int => max($max, $g->id()), 0) + 1;
                $row = ['id' => $id, 'section_id' => $sectionId, 'student_id' => $studentId, 'value' => $value,
                    'remarks' => $remarks, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now];
                Grade::fromArray($row);
                $data['grades'][] = $row;
                $seq = self::append($data, $now, 'entered', $row, null, $value, null, $remarks, null, $actorId);
            } else {
                $row = $data['grades'][$index];
                if ($row['status'] !== 'draft') {
                    throw new HttpException(409, 'Validation failed.', ['status' => 'Submitted or finalized grades cannot be edited.']);
                }
                $old = $row['value'];
                $oldRemarks = $row['remarks'];
                $row = array_replace($row, ['value' => $value, 'remarks' => $remarks, 'updated_at' => $now]);
                Grade::fromArray($row);
                $data['grades'][$index] = $row;
                // L3: every save (including a remarks-only change) is one 'updated' row carrying old/new remarks.
                $seq = self::append($data, $now, 'updated', $row, $old, $value, $oldRemarks, $remarks, null, $actorId);
            }
            return ['grade' => Grade::fromArray($row, $seq), 'created' => $created];
        }));
    }

    public function submitSection(int $sectionId, array $rosterIds, string $now, int $actorId): array
    {
        return $this->store->transaction(function (array &$data) use ($sectionId, $rosterIds, $now, $actorId): array {
            $this->snapshot($data);
            $present = [];
            foreach ($data['grades'] ?? [] as $row) {
                if ($row['section_id'] === $sectionId) {
                    $present[$row['student_id']] = true;
                }
            }
            $missing = array_values(array_filter($rosterIds, static fn (int $id): bool => !isset($present[$id])));
            if ($missing !== []) {
                throw new HttpException(409, 'Validation failed.', ['grades_missing' => $missing]);
            }
            $changed = self::transition($data, $sectionId, 'draft', 'submitted', 'submitted', $now, $actorId, null, $rosterIds);
            if ($changed === []) {
                throw new HttpException(409, 'Validation failed.', ['status' => 'There are no draft grades to submit.']);
            }
            return $changed;
        });
    }

    public function returnSection(int $sectionId, string $reason, string $now, int $actorId, ?int $ownStudentId = null): array
    {
        return $this->store->transaction(function (array &$data) use ($sectionId, $reason, $now, $actorId, $ownStudentId): array {
            $this->snapshot($data);
            self::requireNotEntrant($data, $sectionId, $actorId);
            foreach ($data['grades'] ?? [] as $row) { // L1: the actor's own student record has a grade row in the section
                if ($ownStudentId !== null && $row['section_id'] === $sectionId && $row['student_id'] === $ownStudentId) {
                    throw new HttpException(403, 'Access denied.');
                }
            }
            $changed = self::transition($data, $sectionId, 'submitted', 'draft', 'returned', $now, $actorId, $reason, null);
            if ($changed === []) {
                throw new HttpException(409, 'Validation failed.', ['status' => 'There are no submitted grades to return.']);
            }
            return $changed;
        });
    }

    public function finalizeSection(int $sectionId, string $now, int $actorId, ?int $ownStudentId = null): array
    {
        // Fixed lock order: enrollment (roster re-read under it), then grades. Nothing takes grades before enrollment.
        return $this->catalog->withEnrollmentLock($sectionId, fn (array $roster): array => $this->store->transaction(
            function (array &$data) use ($sectionId, $roster, $now, $actorId, $ownStudentId): array {
                $this->snapshot($data);
                self::requireNotEntrant($data, $sectionId, $actorId);
                $status = [];
                foreach ($data['grades'] ?? [] as $row) {
                    if ($row['section_id'] === $sectionId) {
                        $status[$row['student_id']] = $row['status'];
                    }
                }
                // M1 under the locks: the actor's own student record is on the locked roster or has a grade row.
                if ($ownStudentId !== null && (in_array($ownStudentId, $roster, true) || isset($status[$ownStudentId]))) {
                    throw new HttpException(403, 'Access denied.');
                }
                $missing = array_values(array_filter($roster, static fn (int $id): bool => !isset($status[$id]) || $status[$id] === 'draft'));
                if ($missing !== []) {
                    throw new HttpException(409, 'Validation failed.', ['grades_missing' => $missing]);
                }
                $changed = self::transition($data, $sectionId, 'submitted', 'finalized', 'finalized', $now, $actorId, null, null);
                if ($changed === []) {
                    throw new HttpException(409, 'Validation failed.', ['status' => 'There are no submitted grades to finalize.']);
                }
                return $changed;
            }));
    }

    public function correct(int $id, string $value, string $reason, int $expectedSeq, string $now, int $actorId): Grade
    {
        self::requireDemoValue($value);
        return $this->store->transaction(function (array &$data) use ($id, $value, $reason, $expectedSeq, $now, $actorId): Grade {
            [, $history] = $this->snapshot($data);
            foreach ($data['grades'] ?? [] as $index => $row) {
                if ($row['id'] !== $id) {
                    continue;
                }
                if ($row['status'] !== 'finalized') {
                    throw new HttpException(409, 'Validation failed.', ['status' => 'Only finalized grades can be corrected.']);
                }
                self::requireNotEntrant($data, $row['section_id'], $actorId);
                $latest = 0;
                foreach ($history as $entry) {
                    if ($entry['grade_id'] === $id) {
                        $latest = $entry['seq'];
                    }
                }
                if ($latest !== $expectedSeq) {
                    throw new HttpException(409, 'Validation failed.', ['stale_grade' => 'This grade changed since you loaded it. Reload and try again.']);
                }
                if ($row['value'] === $value) {
                    throw new HttpException(409, 'Validation failed.', ['value' => 'The new value must differ from the current value.']);
                }
                $old = $row['value'];
                $row = array_replace($row, ['value' => $value, 'updated_at' => $now]);
                Grade::fromArray($row);
                $data['grades'][$index] = $row;
                return Grade::fromArray($row, self::append($data, $now, 'corrected', $row, $old, $value, $row['remarks'], $row['remarks'], $reason, $actorId));
            }
            throw new HttpException(404, 'Grade not found.');
        });
    }

    /** SoD (b): the actor entered, updated or submitted grades in this section. */
    private static function requireNotEntrant(array $data, int $sectionId, int $actorId): void
    {
        foreach ($data['history'] ?? [] as $entry) {
            if ($entry['section_id'] === $sectionId && $entry['actor_id'] === $actorId
                && in_array($entry['action'], ['entered', 'updated', 'submitted'], true)) {
                throw new HttpException(403, 'Access denied.');
            }
        }
    }

    /**
     * Moves every row of the section from $from to $to (optionally only $onlyStudents) and appends one history row each.
     * @return list<Grade>
     */
    private static function transition(array &$data, int $sectionId, string $from, string $to, string $action, string $now, int $actorId, ?string $reason, ?array $onlyStudents): array
    {
        $changed = [];
        foreach ($data['grades'] ?? [] as $index => $row) {
            if ($row['section_id'] !== $sectionId || $row['status'] !== $from
                || ($onlyStudents !== null && !in_array($row['student_id'], $onlyStudents, true))) {
                continue;
            }
            $row['status'] = $to;
            $row['updated_at'] = $now;
            $data['grades'][$index] = $row;
            $changed[] = Grade::fromArray($row, self::append($data, $now, $action, $row, null, null, null, null, $reason, $actorId));
        }
        return $changed;
    }

    /** @return int the new history seq */
    private static function append(array &$data, string $at, string $action, array $grade, ?string $old, ?string $new, ?string $oldRemarks, ?string $newRemarks, ?string $reason, int $actorId): int
    {
        $data['history'] ??= [];
        $seq = count($data['history']) + 1;
        $data['history'][] = ['seq' => $seq, 'at' => $at, 'action' => $action, 'grade_id' => $grade['id'],
            'section_id' => $grade['section_id'], 'student_id' => $grade['student_id'], 'old_value' => $old,
            'new_value' => $new, 'old_remarks' => $oldRemarks, 'new_remarks' => $newRemarks, 'reason' => $reason, 'actor_id' => $actorId];
        return $seq;
    }

    /** History remarks: null or a valid non-empty remark (empty is stored as null). */
    private static function storedRemarks(mixed $value): bool
    {
        return $value === null || ($value !== '' && Grade::validRemarks($value));
    }

    /** Mirror the V0010 value domain for direct mock repository writes. */
    private static function requireDemoValue(string $value): void
    {
        if (!GradeScale::demo()->allows($value)) {
            throw new HttpException(422, 'Validation failed.', ['value' => 'Use a value from the grading scale.']);
        }
    }

    /** @return array{0: list<Grade>, 1: list<array>} validated rows (with latest history seq) and history; anything inconsistent fails closed. */
    private function snapshot(array $data): array
    {
        $fail = static fn () => throw new RuntimeException('Invalid grade store.');
        if (array_diff(array_keys($data), ['grades', 'history']) !== []) {
            $fail();
        }
        $rows = $data['grades'] ?? [];
        $history = $data['history'] ?? [];
        if (!is_array($rows) || !array_is_list($rows) || !is_array($history) || !array_is_list($history)) {
            $fail();
        }
        $state = [];
        $latest = [];
        $demoScale = GradeScale::demo();
        foreach ($history as $index => $e) {
            if (!is_array($e) || array_keys($e) !== self::HISTORY_KEYS || $e['seq'] !== $index + 1
                || !is_string($e['at']) || preg_match(self::TIME, $e['at']) !== 1 || !in_array($e['action'], self::ACTIONS, true)
                || !is_int($e['grade_id']) || $e['grade_id'] < 1 || !is_int($e['section_id']) || $e['section_id'] < 1
                || !is_int($e['student_id']) || $e['student_id'] < 1 || !is_int($e['actor_id']) || $e['actor_id'] < 1
                || ($e['old_value'] !== null && (!is_string($e['old_value']) || !$demoScale->allows($e['old_value'])))
                || ($e['new_value'] !== null && (!is_string($e['new_value']) || !$demoScale->allows($e['new_value'])))
                || !self::storedRemarks($e['old_remarks']) || !self::storedRemarks($e['new_remarks'])
                || (in_array($e['action'], ['corrected', 'returned'], true) !== ($e['reason'] !== null))
                || ($e['reason'] !== null && !Grade::validReason($e['reason']))) {
                $fail();
            }
            $s = $state[$e['grade_id']] ?? null;
            $valued = in_array($e['action'], ['entered', 'updated', 'corrected'], true);
            if (($valued && $e['new_value'] === null)
                || (!$valued && ($e['old_value'] !== null || $e['new_value'] !== null || $e['old_remarks'] !== null || $e['new_remarks'] !== null))
                || ($e['action'] !== 'entered' && ($s === null || $s['section_id'] !== $e['section_id'] || $s['student_id'] !== $e['student_id']))) {
                $fail();
            }
            [$needStatus, $next] = ['entered' => [null, 'draft'], 'updated' => ['draft', 'draft'], 'submitted' => ['draft', 'submitted'],
                'returned' => ['submitted', 'draft'], 'finalized' => ['submitted', 'finalized'], 'corrected' => ['finalized', 'finalized']][$e['action']];
            if (($s['status'] ?? null) !== $needStatus
                || (in_array($e['action'], ['updated', 'corrected'], true) && ($e['old_value'] !== $s['value'] || ($e['action'] === 'corrected' && $e['new_value'] === $s['value'])))
                || ($e['action'] === 'entered' && ($e['old_value'] !== null || $e['old_remarks'] !== null))
                // L3 replay: old_remarks is the remarks before; a correction leaves remarks unchanged.
                || (in_array($e['action'], ['updated', 'corrected'], true) && $e['old_remarks'] !== $s['remarks'])
                || ($e['action'] === 'corrected' && $e['new_remarks'] !== $s['remarks'])) {
                $fail();
            }
            $state[$e['grade_id']] = ['status' => $next, 'value' => $valued ? $e['new_value'] : $s['value'],
                'remarks' => $valued ? $e['new_remarks'] : $s['remarks'],
                'section_id' => $e['section_id'], 'student_id' => $e['student_id']];
            $latest[$e['grade_id']] = $e['seq'];
        }
        $grades = [];
        $ids = [];
        $pairs = [];
        foreach ($rows as $row) {
            try {
                $grade = Grade::fromArray($row, $latest[$row['id'] ?? 0] ?? 0);
            } catch (RuntimeException) {
                $fail();
            }
            $pair = $grade->sectionId() . ':' . $grade->studentId();
            $s = $state[$grade->id()] ?? null;
            if (!$demoScale->allows($grade->value()) || isset($ids[$grade->id()]) || isset($pairs[$pair]) || $s === null || $s['status'] !== $grade->status()
                || $s['value'] !== $grade->value() || $s['remarks'] !== $grade->row()['remarks'] || $s['section_id'] !== $grade->sectionId() || $s['student_id'] !== $grade->studentId()) {
                $fail();
            }
            $ids[$grade->id()] = $pairs[$pair] = true;
            $grades[] = $grade;
        }
        if (count($ids) !== count($state)) {
            $fail();
        }
        return [$grades, $history];
    }
}
