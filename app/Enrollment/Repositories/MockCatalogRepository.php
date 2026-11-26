<?php
declare(strict_types=1);

namespace App\Enrollment\Repositories;

use App\Core\HttpException;
use App\Core\JsonStore;
use App\Enrollment\Models\Enrollment;
use App\Enrollment\Models\Section;
use App\Enrollment\Models\Term;
use InvalidArgumentException;
use RuntimeException;

/** Terms, sections, assignments and enrollments share one store and one exclusive lock per write. */
final class MockCatalogRepository implements CatalogRepositoryInterface
{
    /** [state before ('' = none)][history action] => state after. */
    private const TRANSITIONS = ['' => ['requested' => 'pending'],
        'pending' => ['approved' => 'approved', 'rejected' => 'rejected', 'dropped' => 'dropped'],
        'approved' => ['dropped' => 'dropped']];
    private const TERM_MUTABLE = ['name', 'start_date', 'end_date', 'is_current', 'enrollment_open'];
    private const SECTION_MUTABLE = ['course_title', 'units', 'capacity', 'delivery_mode', 'meetings'];

    public function __construct(private readonly JsonStore $store)
    {
    }

    public function terms(): array
    {
        $terms = $this->snapshot($this->store->read())['terms'];
        usort($terms, static fn (Term $a, Term $b): int => [$a->startDate(), $a->id()] <=> [$b->startDate(), $b->id()]);
        return $terms;
    }

    public function findTerm(int $id): ?Term
    {
        foreach ($this->snapshot($this->store->read())['terms'] as $term) {
            if ($term->id() === $id) {
                return $term;
            }
        }
        return null;
    }

    public function createTerm(array $fields, string $now): Term
    {
        return $this->store->transaction(function (array &$data) use ($fields, $now): Term {
            $terms = $this->snapshot($data)['terms'];
            foreach ($terms as $existing) {
                if ($existing->code() === $fields['code']) {
                    throw new HttpException(409, 'Validation failed.', ['code' => 'This term code is already in use.']);
                }
            }
            $id = array_reduce($terms, static fn (int $max, Term $term): int => max($max, $term->id()), 0) + 1;
            $row = ['id' => $id, 'code' => $fields['code'], 'name' => $fields['name'],
                'start_date' => $fields['start_date'], 'end_date' => $fields['end_date'],
                'is_current' => $fields['is_current'] ?? false, 'enrollment_open' => $fields['enrollment_open'] ?? false,
                'created_at' => $now, 'updated_at' => $now];
            $term = Term::fromArray($row);
            if ($term->isCurrent()) {
                self::clearCurrent($data, $now);
            }
            $data['terms'][] = $row;
            $data['sections'] ??= [];
            self::ensureAssignmentKeys($data);
            return $term;
        });
    }

    public function updateTerm(int $id, array $changes, string $now, ?callable $check = null): Term
    {
        if ($changes === [] || array_diff(array_keys($changes), self::TERM_MUTABLE) !== []) {
            throw new InvalidArgumentException('Unsupported term change.');
        }
        return $this->store->transaction(function (array &$data) use ($id, $changes, $now, $check): Term {
            $this->snapshot($data);
            foreach ($data['terms'] ?? [] as $index => $row) {
                if ($row['id'] === $id) {
                    $merged = array_replace($row, $changes, ['updated_at' => $now]);
                    if ($check !== null) {
                        $check($merged);
                    }
                    $term = Term::fromArray($merged);
                    if ($term->isCurrent()) {
                        self::clearCurrent($data, $now);
                    }
                    $data['terms'][$index] = $merged;
                    self::ensureAssignmentKeys($data);
                    return $term;
                }
            }
            throw new HttpException(404, 'Term not found.');
        });
    }

    public function findSection(int $id): ?Section
    {
        foreach ($this->snapshot($this->store->read())['sections'] as $section) {
            if ($section->id() === $id) {
                return $section;
            }
        }
        return null;
    }

    public function searchSections(array $filters, int $page, int $perPage): array
    {
        $needle = mb_strtolower(trim($filters['search'] ?? ''), 'UTF-8');
        $matches = array_values(array_filter($this->snapshot($this->store->read())['sections'], static function (Section $section) use ($filters, $needle): bool {
            if (isset($filters['term_id']) && $section->termId() !== $filters['term_id']) {
                return false;
            }
            if (isset($filters['course_code']) && $section->courseCode() !== $filters['course_code']) {
                return false;
            }
            $row = $section->toArray();
            return $needle === '' || str_contains(mb_strtolower($row['course_code'], 'UTF-8'), $needle)
                || str_contains(mb_strtolower($row['course_title'], 'UTF-8'), $needle)
                || str_contains(mb_strtolower($row['section_code'], 'UTF-8'), $needle);
        }));
        usort($matches, static fn (Section $a, Section $b): int => $a->termId() <=> $b->termId()
            ?: strcmp($a->courseCode(), $b->courseCode()) ?: strcmp($a->sectionCode(), $b->sectionCode()) ?: $a->id() <=> $b->id());
        return ['sections' => array_slice($matches, ($page - 1) * $perPage, $perPage), 'total' => count($matches)];
    }

    public function createSection(array $fields, string $now): Section
    {
        return $this->store->transaction(function (array &$data) use ($fields, $now): Section {
            $snapshot = $this->snapshot($data);
            $termIds = array_map(static fn (Term $term): int => $term->id(), $snapshot['terms']);
            if (!in_array($fields['term_id'], $termIds, true)) {
                throw new HttpException(422, 'Validation failed.', ['term_id' => 'Unknown term.']);
            }
            foreach ($snapshot['sections'] as $existing) {
                if ($existing->termId() === $fields['term_id'] && $existing->courseCode() === $fields['course_code']
                    && $existing->sectionCode() === $fields['section_code']) {
                    throw new HttpException(409, 'Validation failed.', ['section_code' => 'This section already exists for the course in this term.']);
                }
            }
            $id = array_reduce($snapshot['sections'], static fn (int $max, Section $section): int => max($max, $section->id()), 0) + 1;
            $row = ['id' => $id, 'term_id' => $fields['term_id'], 'course_code' => $fields['course_code'],
                'course_title' => $fields['course_title'], 'units' => $fields['units'], 'section_code' => $fields['section_code'],
                'capacity' => $fields['capacity'], 'approved_count' => 0,
                'delivery_mode' => $fields['delivery_mode'] ?? 'scheduled', 'meetings' => $fields['meetings'],
                'created_at' => $now, 'updated_at' => $now];
            $section = Section::fromArray($row);
            $data['sections'][] = $row;
            self::ensureAssignmentKeys($data);
            return $section;
        });
    }

    public function updateSection(int $id, array $changes, string $now, ?callable $check = null): Section
    {
        if ($changes === [] || array_diff(array_keys($changes), self::SECTION_MUTABLE) !== []) {
            throw new InvalidArgumentException('Unsupported section change.');
        }
        return $this->store->transaction(function (array &$data) use ($id, $changes, $now, $check): Section {
            $snapshot = $this->snapshot($data);
            foreach ($data['sections'] ?? [] as $index => $row) {
                if ($row['id'] === $id) {
                    $merged = array_replace($row, $changes, ['updated_at' => $now]);
                    if ($check !== null) {
                        $check($merged);
                    }
                    $section = Section::fromArray($merged);
                    foreach ($snapshot['assignments'] as $assignment) {
                        if ($assignment['section_id'] !== $id) {
                            continue;
                        }
                        $conflict = self::conflictingSection($section, $assignment['faculty_id'], $snapshot['sections'], $snapshot['assignments']);
                        if ($conflict !== null) {
                            throw new HttpException(409, 'Validation failed.', ['meetings' => 'Instructor ' . $assignment['faculty_id']
                                . ' already teaches ' . self::sectionLabel($conflict) . ' at an overlapping time.']);
                        }
                    }
                    // OQ-3: a meeting change must not make any student's approved classes overlap (same lock).
                    if (array_key_exists('meetings', $changes)) {
                        foreach ($snapshot['enrollments'] as $enrollment) {
                            if ($enrollment->sectionId() !== $id || $enrollment->status() !== 'approved') {
                                continue;
                            }
                            $clash = self::clashWith($section, self::studentSections($snapshot, $enrollment->studentId(), ['approved'], $section->termId()));
                            if ($clash !== null) {
                                throw new HttpException(409, 'Validation failed.', ['meetings' => 'Student ' . $enrollment->studentId()
                                    . ' has an approved enrollment in ' . self::sectionLabel($clash) . ' at an overlapping time.']);
                            }
                        }
                    }
                    // Persist the stored row, never the response's computed remaining field.
                    $data['sections'][$index] = $merged;
                    self::ensureAssignmentKeys($data);
                    return $section;
                }
            }
            throw new HttpException(404, 'Section not found.');
        });
    }

    public function assignedFacultyIds(array $sectionIds): array
    {
        $snapshot = $this->snapshot($this->store->read());
        $result = array_fill_keys($sectionIds, []);
        foreach ($snapshot['assignments'] as $assignment) {
            if (array_key_exists($assignment['section_id'], $result)) {
                $result[$assignment['section_id']][] = $assignment['faculty_id'];
            }
        }
        return $result;
    }

    public function sectionIdsForFaculty(int $facultyId): array
    {
        $snapshot = $this->snapshot($this->store->read());
        $ids = [];
        foreach ($snapshot['assignments'] as $assignment) {
            if ($assignment['faculty_id'] === $facultyId) {
                $ids[] = $assignment['section_id'];
            }
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    public function assignInstructor(int $sectionId, int $facultyId, string $now, int $actorId): array
    {
        return $this->store->transaction(function (array &$data) use ($sectionId, $facultyId, $now, $actorId): array {
            $snapshot = $this->snapshot($data);
            $section = self::sectionById($snapshot['sections'], $sectionId)
                ?? throw new HttpException(404, 'Section not found.');
            $facultyIds = [];
            foreach ($snapshot['assignments'] as $assignment) {
                if ($assignment['section_id'] === $sectionId) {
                    $facultyIds[] = $assignment['faculty_id'];
                }
            }
            if (in_array($facultyId, $facultyIds, true)) {
                throw new HttpException(409, 'Validation failed.', ['faculty_id' => 'This instructor is already assigned to this section.']);
            }
            if (count($facultyIds) >= 5) {
                throw new HttpException(409, 'Validation failed.', ['instructors' => 'A section can have at most 5 instructors.']);
            }
            $conflict = self::conflictingSection($section, $facultyId, $snapshot['sections'], $snapshot['assignments']);
            if ($conflict !== null) {
                throw new HttpException(409, 'Validation failed.', ['faculty_id' => 'This instructor already teaches '
                    . self::sectionLabel($conflict) . ' at an overlapping time.']);
            }
            self::ensureAssignmentKeys($data);
            $data['assignments'][] = ['section_id' => $sectionId, 'faculty_id' => $facultyId];
            self::appendHistory($data, $now, 'assigned', $sectionId, $facultyId, $actorId);
            $facultyIds[] = $facultyId;
            return ['section' => $section, 'faculty_ids' => $facultyIds];
        });
    }

    public function unassignInstructor(int $sectionId, int $facultyId, string $now, int $actorId): array
    {
        return $this->store->transaction(function (array &$data) use ($sectionId, $facultyId, $now, $actorId): array {
            $snapshot = $this->snapshot($data);
            $section = self::sectionById($snapshot['sections'], $sectionId)
                ?? throw new HttpException(404, 'Section not found.');
            $found = false;
            $facultyIds = [];
            $remaining = [];
            foreach ($snapshot['assignments'] as $assignment) {
                if ($assignment['section_id'] === $sectionId && $assignment['faculty_id'] === $facultyId) {
                    $found = true;
                    continue;
                }
                $remaining[] = $assignment;
                if ($assignment['section_id'] === $sectionId) {
                    $facultyIds[] = $assignment['faculty_id'];
                }
            }
            if (!$found) {
                throw new HttpException(404, 'Instructor assignment not found.');
            }
            self::ensureAssignmentKeys($data);
            $data['assignments'] = $remaining;
            self::appendHistory($data, $now, 'unassigned', $sectionId, $facultyId, $actorId);
            return ['section' => $section, 'faculty_ids' => $facultyIds];
        });
    }

    public function requestEnrollment(int $studentId, int $sectionId, string $now, int $actorId): Enrollment
    {
        return $this->store->transaction(function (array &$data) use ($studentId, $sectionId, $now, $actorId): Enrollment {
            $snapshot = $this->snapshot($data);
            $section = self::sectionById($snapshot['sections'], $sectionId)
                ?? throw new HttpException(422, 'Validation failed.', ['section_id' => 'Unknown section.']);
            $found = false;
            foreach ($snapshot['terms'] as $term) {
                if ($term->id() === $section->termId()) {
                    $found = true;
                    if (!$term->toArray()['enrollment_open']) {
                        throw new HttpException(409, 'Validation failed.', ['section_id' => 'Enrollment is closed for this term.']);
                    }
                }
            }
            if (!$found) {
                throw new RuntimeException('Invalid stored section.'); // L4: fail closed, never proceed without the term
            }
            foreach ($snapshot['enrollments'] as $existing) {
                if ($existing->studentId() === $studentId && $existing->sectionId() === $sectionId
                    && in_array($existing->status(), ['pending', 'approved'], true)) {
                    throw new HttpException(409, 'Validation failed.', ['section_id' => 'You already have a pending or approved request for this section.']);
                }
            }
            if ($section->approvedCount() >= $section->capacity()) {
                throw new HttpException(409, 'Validation failed.', ['section_id' => 'This section is full.']);
            }
            $clash = self::clashWith($section, self::studentSections($snapshot, $studentId, ['pending', 'approved'], $section->termId()));
            if ($clash !== null) {
                throw new HttpException(409, 'Validation failed.', ['section_id' => 'This section overlaps ' . self::sectionLabel($clash) . ' in your schedule.']);
            }
            $id = array_reduce($snapshot['enrollments'], static fn (int $max, Enrollment $e): int => max($max, $e->id()), 0) + 1;
            $row = ['id' => $id, 'student_id' => $studentId, 'section_id' => $sectionId, 'status' => 'pending', 'requested_at' => $now,
                'decided_at' => null, 'decided_by' => null, 'dropped_at' => null, 'reason' => null];
            $enrollment = Enrollment::fromArray($row, $section->termId());
            self::ensureEnrollmentKeys($data);
            $data['enrollments'][] = $row;
            self::appendEnrollmentHistory($data, $id, $now, 'requested', $actorId, null);
            return $enrollment;
        });
    }

    public function decideEnrollment(int $id, string $status, ?string $reason, string $now, int $actorId): Enrollment
    {
        if (!in_array($status, ['approved', 'rejected'], true) || ($status === 'rejected' && $reason === null)) {
            throw new InvalidArgumentException('Unsupported enrollment decision.');
        }
        return $this->store->transaction(function (array &$data) use ($id, $status, $reason, $now, $actorId): Enrollment {
            $snapshot = $this->snapshot($data);
            $index = self::enrollmentIndex($snapshot['enrollments'], $id) ?? throw new HttpException(404, 'Enrollment not found.');
            $enrollment = $snapshot['enrollments'][$index];
            if ($enrollment->status() !== 'pending') {
                throw new HttpException(409, 'Validation failed.', ['status' => 'Only a pending enrollment can be decided.']);
            }
            if ($status === 'approved') {
                $section = self::sectionById($snapshot['sections'], $enrollment->sectionId());
                if ($section->approvedCount() >= $section->capacity()) {
                    throw new HttpException(409, 'Validation failed.', ['status' => 'This section is full.']);
                }
                $clash = self::clashWith($section, self::studentSections($snapshot, $enrollment->studentId(), ['approved'], $enrollment->termId()));
                if ($clash !== null) {
                    throw new HttpException(409, 'Validation failed.', ['status' => 'The student already has an approved class at an overlapping time: '
                        . self::sectionLabel($clash) . '.']);
                }
                foreach ($data['sections'] as $i => $row) {
                    if ($row['id'] === $section->id()) {
                        $data['sections'][$i]['approved_count']++;
                    }
                }
            }
            $stamp = max($now, $enrollment->row()['requested_at']);
            $row = array_replace($enrollment->row(), ['status' => $status, 'decided_at' => $stamp, 'decided_by' => $actorId, 'reason' => $reason]);
            $updated = Enrollment::fromArray($row, $enrollment->termId());
            $data['enrollments'][$index] = $row;
            self::appendEnrollmentHistory($data, $id, $stamp, $status, $actorId, $reason);
            return $updated;
        });
    }

    public function dropEnrollment(int $id, int $studentId, string $now, int $actorId): Enrollment
    {
        return $this->store->transaction(function (array &$data) use ($id, $studentId, $now, $actorId): Enrollment {
            $snapshot = $this->snapshot($data);
            $index = self::enrollmentIndex($snapshot['enrollments'], $id);
            if ($index === null || $snapshot['enrollments'][$index]->studentId() !== $studentId) {
                throw new HttpException(404, 'Enrollment not found.');
            }
            $enrollment = $snapshot['enrollments'][$index];
            if (!in_array($enrollment->status(), ['pending', 'approved'], true)) {
                throw new HttpException(409, 'Validation failed.', ['status' => 'Only a pending or approved enrollment can be dropped.']);
            }
            $found = false;
            foreach ($snapshot['terms'] as $term) {
                if ($term->id() === $enrollment->termId()) {
                    $found = true;
                    if (!$term->toArray()['enrollment_open']) {
                        throw new HttpException(409, 'Validation failed.', ['status' => 'Enrollment is closed for this term.']);
                    }
                }
            }
            if (!$found) {
                throw new RuntimeException('Invalid stored section.'); // L4: fail closed, never proceed without the term
            }
            if ($enrollment->status() === 'approved') {
                foreach ($data['sections'] as $i => $row) {
                    if ($row['id'] === $enrollment->sectionId()) {
                        // E9: legacy stores may carry a count below the approved rows; refuse rather than go negative.
                        $row['approved_count'] > 0 ? $data['sections'][$i]['approved_count']-- : throw new RuntimeException('Invalid enrollment store.');
                    }
                }
            }
            $row = $enrollment->row();
            $stamp = max($now, $row['decided_at'] ?? $row['requested_at']);
            $row = array_replace($row, ['status' => 'dropped', 'dropped_at' => $stamp]);
            $updated = Enrollment::fromArray($row, $enrollment->termId());
            $data['enrollments'][$index] = $row;
            self::appendEnrollmentHistory($data, $id, $stamp, 'dropped', $actorId, null);
            return $updated;
        });
    }

    public function findEnrollment(int $id): ?Enrollment
    {
        foreach ($this->snapshot($this->store->read())['enrollments'] as $enrollment) {
            if ($enrollment->id() === $id) {
                return $enrollment;
            }
        }
        return null;
    }

    public function searchEnrollments(array $filters, int $page, int $perPage): array
    {
        $matches = array_values(array_filter($this->snapshot($this->store->read())['enrollments'], static fn (Enrollment $e): bool
            => (!isset($filters['status']) || $e->status() === $filters['status'])
            && (!isset($filters['term_id']) || $e->termId() === $filters['term_id'])
            && (!isset($filters['section_id']) || $e->sectionId() === $filters['section_id'])
            && (!isset($filters['student_id']) || $e->studentId() === $filters['student_id'])));
        usort($matches, static fn (Enrollment $a, Enrollment $b): int => $a->id() <=> $b->id());
        return ['enrollments' => array_slice($matches, ($page - 1) * $perPage, $perPage), 'total' => count($matches)];
    }

    public function approvedStudentIds(int $sectionId): array
    {
        $ids = [];
        foreach ($this->snapshot($this->store->read())['enrollments'] as $enrollment) {
            if ($enrollment->sectionId() === $sectionId && $enrollment->status() === 'approved') {
                $ids[$enrollment->studentId()] = $enrollment->studentId();
            }
        }
        sort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    public function withEnrollmentLock(int $sectionId, callable $fn): mixed
    {
        // shortcut: JsonStore has no lock-only call, so the (unchanged) data is rewritten; use a lock-only method if that matters.
        return $this->store->transaction(function (array &$data) use ($sectionId, $fn): mixed {
            $ids = [];
            foreach ($this->snapshot($data)['enrollments'] as $enrollment) {
                if ($enrollment->sectionId() === $sectionId && $enrollment->status() === 'approved') {
                    $ids[$enrollment->studentId()] = $enrollment->studentId();
                }
            }
            sort($ids, SORT_NUMERIC);
            return $fn(array_values($ids));
        });
    }

    private static function ensureEnrollmentKeys(array &$data): void
    {
        $data['enrollments'] ??= [];
        $data['enrollment_history'] ??= [];
    }

    private static function appendEnrollmentHistory(array &$data, int $enrollmentId, string $at, string $action, int $actorId, ?string $reason): void
    {
        $seq = 1;
        foreach ($data['enrollment_history'] as $entry) {
            if ($entry['enrollment_id'] === $enrollmentId) {
                $seq++;
            }
        }
        $data['enrollment_history'][] = ['enrollment_id' => $enrollmentId, 'seq' => $seq, 'at' => $at,
            'action' => $action, 'actor_id' => $actorId, 'reason' => $reason];
    }

    /** @param list<Enrollment> $enrollments */
    private static function enrollmentIndex(array $enrollments, int $id): ?int
    {
        foreach ($enrollments as $index => $enrollment) {
            if ($enrollment->id() === $id) {
                return $index;
            }
        }
        return null;
    }

    /** @param list<string> $statuses @return list<Section> the student's sections in the term with a row in $statuses. */
    private static function studentSections(array $snapshot, int $studentId, array $statuses, int $termId): array
    {
        $sections = [];
        foreach ($snapshot['enrollments'] as $enrollment) {
            if ($enrollment->studentId() === $studentId && $enrollment->termId() === $termId && in_array($enrollment->status(), $statuses, true)) {
                $sections[] = self::sectionById($snapshot['sections'], $enrollment->sectionId());
            }
        }
        return $sections;
    }

    /** First section (id order) among $others that overlaps $target's meetings; sections without meetings never clash. */
    private static function clashWith(Section $target, array $others): ?Section
    {
        usort($others, static fn (Section $a, Section $b): int => $a->id() <=> $b->id());
        foreach ($others as $other) {
            if ($other->id() === $target->id()) {
                continue;
            }
            foreach ($target->meetings() as $meeting) {
                foreach ($other->meetings() as $otherMeeting) {
                    if ($meeting['day'] === $otherMeeting['day'] && $meeting['start'] < $otherMeeting['end']
                        && $otherMeeting['start'] < $meeting['end']) {
                        return $other;
                    }
                }
            }
        }
        return null;
    }

    /** Validates enrollment rows and history; approved_count is deliberately not enforced here (E9, verify.php reports it). */
    private static function enrollmentSnapshot(array $data, array $sectionById): array
    {
        $rows = $data['enrollments'] ?? [];
        $history = $data['enrollment_history'] ?? [];
        if (!is_array($rows) || !array_is_list($rows) || !is_array($history) || !array_is_list($history)) {
            throw new RuntimeException('Invalid enrollment store.');
        }
        $enrollments = [];
        $byId = [];
        $active = [];
        foreach ($rows as $row) {
            $section = is_array($row) && is_int($row['section_id'] ?? null) ? ($sectionById[$row['section_id']] ?? null) : null;
            if ($section === null) {
                throw new RuntimeException('Invalid enrollment store.');
            }
            try {
                $enrollment = Enrollment::fromArray($row, $section->termId());
            } catch (RuntimeException) {
                throw new RuntimeException('Invalid enrollment store.');
            }
            $pair = $enrollment->studentId() . ':' . $enrollment->sectionId();
            if (isset($byId[$enrollment->id()]) || (in_array($enrollment->status(), ['pending', 'approved'], true) && isset($active[$pair]))) {
                throw new RuntimeException('Invalid enrollment store.');
            }
            $byId[$enrollment->id()] = true;
            if (in_array($enrollment->status(), ['pending', 'approved'], true)) {
                $active[$pair] = true;
            }
            $enrollments[] = $enrollment;
        }
        $seq = [];
        $state = [];
        foreach ($history as $entry) {
            if (!is_array($entry) || array_keys($entry) !== ['enrollment_id', 'seq', 'at', 'action', 'actor_id', 'reason']
                || !is_int($entry['enrollment_id']) || !isset($byId[$entry['enrollment_id']])
                || $entry['seq'] !== ($seq[$entry['enrollment_id']] ?? 0) + 1
                || !is_string($entry['at']) || preg_match(Enrollment::TIMESTAMP, $entry['at']) !== 1
                || !is_string($entry['action']) || !is_int($entry['actor_id']) || $entry['actor_id'] < 1
                || ($entry['reason'] !== null && !Enrollment::validReason($entry['reason']))
                || ($entry['action'] === 'rejected' && $entry['reason'] === null)) {
                throw new RuntimeException('Invalid enrollment store.');
            }
            $next = self::TRANSITIONS[$state[$entry['enrollment_id']] ?? ''][$entry['action']] ?? null;
            if ($next === null) {
                throw new RuntimeException('Invalid enrollment store.');
            }
            $seq[$entry['enrollment_id']] = $entry['seq'];
            $state[$entry['enrollment_id']] = $next;
        }
        foreach ($enrollments as $enrollment) {
            if (($state[$enrollment->id()] ?? null) !== $enrollment->status()) {
                throw new RuntimeException('Invalid enrollment store.');
            }
        }
        return $enrollments;
    }

    private static function ensureAssignmentKeys(array &$data): void
    {
        $data['assignments'] ??= [];
        $data['assignment_history'] ??= [];
    }

    private static function appendHistory(array &$data, string $now, string $action, int $sectionId, int $facultyId, int $actorId): void
    {
        $data['assignment_history'][] = ['seq' => count($data['assignment_history']) + 1, 'at' => $now,
            'action' => $action, 'section_id' => $sectionId, 'faculty_id' => $facultyId, 'actor_id' => $actorId];
    }

    private static function sectionById(array $sections, int $id): ?Section
    {
        foreach ($sections as $section) {
            if ($section->id() === $id) {
                return $section;
            }
        }
        return null;
    }

    private static function sectionLabel(Section $section): string
    {
        return $section->courseCode() . '-' . $section->sectionCode() . ' (section ' . $section->id() . ')';
    }

    /** Returns the first clashing section in id order; all actual meetings count regardless of delivery mode. */
    private static function conflictingSection(Section $target, int $facultyId, array $sections, array $assignments): ?Section
    {
        usort($sections, static fn (Section $a, Section $b): int => $a->id() <=> $b->id());
        foreach ($sections as $other) {
            if ($other->id() === $target->id() || $other->termId() !== $target->termId()) {
                continue;
            }
            $assigned = false;
            foreach ($assignments as $assignment) {
                if ($assignment['faculty_id'] === $facultyId && $assignment['section_id'] === $other->id()) {
                    $assigned = true;
                    break;
                }
            }
            if (!$assigned) {
                continue;
            }
            foreach ($target->meetings() as $meeting) {
                foreach ($other->meetings() as $otherMeeting) {
                    if ($meeting['day'] === $otherMeeting['day'] && $meeting['start'] < $otherMeeting['end']
                        && $otherMeeting['start'] < $meeting['end']) {
                        return $other;
                    }
                }
            }
        }
        return null;
    }

    private static function clearCurrent(array &$data, string $now): void
    {
        foreach ($data['terms'] ?? [] as $index => $row) {
            if ($row['is_current']) {
                $data['terms'][$index]['is_current'] = false;
                $data['terms'][$index]['updated_at'] = $now;
            }
        }
    }

    /** Validates every row and cross-row invariant before any read or mutation proceeds. */
    private function snapshot(array $data): array
    {
        if (array_diff(array_keys($data), ['terms', 'sections', 'assignments', 'assignment_history', 'enrollments', 'enrollment_history']) !== []) {
            throw new RuntimeException('Invalid enrollment store.');
        }
        $snapshot = ['terms' => [], 'sections' => []];
        $ids = ['terms' => [], 'sections' => []];
        $current = 0;
        $seen = [];
        foreach ($snapshot as $key => $_) {
            $rows = array_key_exists($key, $data) ? $data[$key] : [];
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new RuntimeException('Invalid enrollment store.');
            }
            foreach ($rows as $row) {
                try {
                    $model = $key === 'terms' ? Term::fromArray($row) : Section::fromArray($row);
                } catch (RuntimeException) {
                    throw new RuntimeException('Invalid enrollment store.');
                }
                if (isset($ids[$key][$model->id()])) {
                    throw new RuntimeException('Invalid enrollment store.');
                }
                $ids[$key][$model->id()] = true;
                $natural = $model instanceof Term
                    ? 't' . "\0" . $model->code()
                    : 's' . "\0" . $model->termId() . "\0" . $model->courseCode() . "\0" . $model->sectionCode();
                if (isset($seen[$natural])) {
                    throw new RuntimeException('Invalid enrollment store.');
                }
                $seen[$natural] = true;
                if ($model instanceof Term && $model->isCurrent() && ++$current > 1) {
                    throw new RuntimeException('Invalid enrollment store.');
                }
                if ($model instanceof Section && !isset($ids['terms'][$model->termId()])) {
                    throw new RuntimeException('Invalid enrollment store.');
                }
                $snapshot[$key][] = $model;
            }
        }
        $assignments = $data['assignments'] ?? [];
        $history = $data['assignment_history'] ?? [];
        if (!is_array($assignments) || !array_is_list($assignments) || !is_array($history) || !array_is_list($history)) {
            throw new RuntimeException('Invalid enrollment store.');
        }
        $sectionById = [];
        foreach ($snapshot['sections'] as $section) {
            $sectionById[$section->id()] = $section;
        }
        $pairs = [];
        $counts = [];
        foreach ($assignments as $assignment) {
            if (!is_array($assignment) || array_keys($assignment) !== ['section_id', 'faculty_id']
                || !is_int($assignment['section_id']) || $assignment['section_id'] < 1
                || !is_int($assignment['faculty_id']) || $assignment['faculty_id'] < 1
                || !isset($sectionById[$assignment['section_id']])) {
                throw new RuntimeException('Invalid enrollment store.');
            }
            $pair = $assignment['section_id'] . ':' . $assignment['faculty_id'];
            $counts[$assignment['section_id']] = ($counts[$assignment['section_id']] ?? 0) + 1;
            if (isset($pairs[$pair]) || $counts[$assignment['section_id']] > 5) {
                throw new RuntimeException('Invalid enrollment store.');
            }
            $pairs[$pair] = true;
        }
        foreach ($assignments as $assignment) {
            $section = $sectionById[$assignment['section_id']];
            if (self::conflictingSection($section, $assignment['faculty_id'], $snapshot['sections'], $assignments) !== null) {
                throw new RuntimeException('Invalid enrollment store.');
            }
        }
        $replayed = [];
        foreach ($history as $index => $entry) {
            if (!is_array($entry) || array_keys($entry) !== ['seq', 'at', 'action', 'section_id', 'faculty_id', 'actor_id']
                || $entry['seq'] !== $index + 1 || !is_string($entry['at'])
                || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $entry['at']) !== 1
                || !in_array($entry['action'], ['assigned', 'unassigned'], true)
                || !is_int($entry['section_id']) || $entry['section_id'] < 1 || !isset($sectionById[$entry['section_id']])
                || !is_int($entry['faculty_id']) || $entry['faculty_id'] < 1
                || !is_int($entry['actor_id']) || $entry['actor_id'] < 1) {
                throw new RuntimeException('Invalid enrollment store.');
            }
            $pair = $entry['section_id'] . ':' . $entry['faculty_id'];
            if ($entry['action'] === 'assigned') {
                if (isset($replayed[$pair])) {
                    throw new RuntimeException('Invalid enrollment store.');
                }
                $replayed[$pair] = true;
            } else {
                if (!isset($replayed[$pair])) {
                    throw new RuntimeException('Invalid enrollment store.');
                }
                unset($replayed[$pair]);
            }
        }
        if (array_diff_key($pairs, $replayed) !== [] || array_diff_key($replayed, $pairs) !== []) {
            throw new RuntimeException('Invalid enrollment store.');
        }
        $snapshot['assignments'] = $assignments;
        $snapshot['enrollments'] = self::enrollmentSnapshot($data, $sectionById);
        return $snapshot;
    }
}
