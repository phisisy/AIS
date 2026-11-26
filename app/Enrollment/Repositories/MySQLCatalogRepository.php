<?php
declare(strict_types=1);

namespace App\Enrollment\Repositories;

use App\Core\HttpException;
use App\Enrollment\Models\Enrollment;
use App\Enrollment\Models\Section;
use App\Enrollment\Models\Term;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/** Catalog adapter on a caller-supplied connection. */
final class MySQLCatalogRepository implements CatalogRepositoryInterface
{
    private const TERM_MUTABLE = ['name', 'start_date', 'end_date', 'is_current', 'enrollment_open'];
    private const SECTION_MUTABLE = ['course_title', 'units', 'capacity', 'delivery_mode', 'meetings'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function terms(): array
    {
        return $this->read(function (): array {
            $terms = $this->loadTerms();
            usort($terms, static fn (Term $a, Term $b): int => [$a->startDate(), $a->id()] <=> [$b->startDate(), $b->id()]);
            return $terms;
        });
    }

    public function findTerm(int $id): ?Term
    {
        return $this->read(fn (): ?Term => self::termById($this->loadTerms(), $id));
    }

    public function createTerm(array $fields, string $now): Term
    {
        return $this->write(function () use ($fields, $now): Term {
            foreach ($this->loadTerms() as $existing) {
                if ($existing->code() === $fields['code']) {
                    throw new HttpException(409, 'Validation failed.', ['code' => 'This term code is already in use.']);
                }
            }
            $row = ['id' => 1, 'code' => $fields['code'], 'name' => $fields['name'],
                'start_date' => $fields['start_date'], 'end_date' => $fields['end_date'],
                'is_current' => $fields['is_current'] ?? false, 'enrollment_open' => $fields['enrollment_open'] ?? false,
                'created_at' => $now, 'updated_at' => $now];
            Term::fromArray($row);
            if ($row['is_current']) {
                $this->clearCurrent($now);
            }
            $this->execute('INSERT INTO terms (code, name, start_date, end_date, is_current, enrollment_open, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$row['code'], $row['name'], $row['start_date'], $row['end_date'], (int) $row['is_current'],
                    (int) $row['enrollment_open'], self::sqlTime($now), self::sqlTime($now)]);
            $row['id'] = (int) $this->pdo->lastInsertId();
            return Term::fromArray($row);
        });
    }

    public function updateTerm(int $id, array $changes, string $now, ?callable $check = null): Term
    {
        if ($changes === [] || array_diff(array_keys($changes), self::TERM_MUTABLE) !== []) {
            throw new InvalidArgumentException('Unsupported term change.');
        }
        return $this->write(function () use ($id, $changes, $now, $check): Term {
            $original = self::termById($this->loadTerms(), $id) ?? throw new HttpException(404, 'Term not found.');
            $row = array_replace($original->toArray(), $changes, ['updated_at' => $now]);
            if ($check !== null) {
                $check($row);
            }
            $term = Term::fromArray($row);
            if ($term->isCurrent()) {
                $this->clearCurrent($now);
            }
            $this->execute('UPDATE terms SET name = ?, start_date = ?, end_date = ?, is_current = ?, enrollment_open = ?, updated_at = ? WHERE id = ?',
                [$row['name'], $row['start_date'], $row['end_date'], (int) $row['is_current'],
                    (int) $row['enrollment_open'], self::sqlTime($now), $id]);
            return $term;
        });
    }

    public function findSection(int $id): ?Section
    {
        return $this->read(fn (): ?Section => self::sectionById($this->loadSections(), $id));
    }

    public function searchSections(array $filters, int $page, int $perPage): array
    {
        return $this->read(function () use ($filters, $page, $perPage): array {
            $where = [];
            $params = [];
            if (isset($filters['term_id'])) {
                $where[] = 'term_id = ?';
                $params[] = $filters['term_id'];
            }
            if (isset($filters['course_code'])) {
                $where[] = 'course_code = ?';
                $params[] = $filters['course_code'];
            }
            $needle = mb_strtolower(trim((string) ($filters['search'] ?? '')), 'UTF-8');
            if ($needle !== '') {
                $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle) . '%';
                $where[] = "(LOWER(course_code) LIKE ? ESCAPE '!' OR LOWER(course_title) LIKE ? ESCAPE '!' OR LOWER(section_code) LIKE ? ESCAPE '!')";
                array_push($params, $pattern, $pattern, $pattern);
            }
            $condition = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
            $count = $this->execute('SELECT COUNT(*) FROM sections' . $condition, $params);
            $total = (int) $count->fetchColumn();
            $list = $this->pdo->prepare('SELECT id FROM sections' . $condition . ' ORDER BY term_id, course_code, section_code, id LIMIT ? OFFSET ?');
            foreach ($params as $index => $value) {
                $list->bindValue($index + 1, $value);
            }
            $list->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
            $list->bindValue(count($params) + 2, ($page - 1) * $perPage, PDO::PARAM_INT);
            $list->execute();
            $ids = array_map('intval', $list->fetchAll(PDO::FETCH_COLUMN));
            $sections = $this->loadSections();
            $byId = [];
            foreach ($sections as $section) {
                $byId[$section->id()] = $section;
            }
            return ['sections' => array_map(static fn (int $id): Section => $byId[$id]
                ?? throw new RuntimeException('Invalid stored section.'), $ids), 'total' => $total];
        });
    }

    public function createSection(array $fields, string $now): Section
    {
        return $this->write(function () use ($fields, $now): Section {
            if (self::termById($this->loadTerms(), $fields['term_id']) === null) {
                throw new HttpException(422, 'Validation failed.', ['term_id' => 'Unknown term.']);
            }
            foreach ($this->loadSections() as $existing) {
                if ($existing->termId() === $fields['term_id'] && $existing->courseCode() === $fields['course_code']
                    && $existing->sectionCode() === $fields['section_code']) {
                    throw new HttpException(409, 'Validation failed.', ['section_code' => 'This section already exists for the course in this term.']);
                }
            }
            $row = ['id' => 1, 'term_id' => $fields['term_id'], 'course_code' => $fields['course_code'],
                'course_title' => $fields['course_title'], 'units' => $fields['units'], 'section_code' => $fields['section_code'],
                'capacity' => $fields['capacity'], 'approved_count' => 0,
                'delivery_mode' => $fields['delivery_mode'] ?? 'scheduled', 'meetings' => $fields['meetings'],
                'created_at' => $now, 'updated_at' => $now];
            Section::fromArray($row);
            $this->execute('INSERT INTO sections (term_id, course_code, course_title, units, section_code, capacity, approved_count, delivery_mode, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$row['term_id'], $row['course_code'], $row['course_title'], $row['units'], $row['section_code'],
                    $row['capacity'], 0, $row['delivery_mode'], self::sqlTime($now), self::sqlTime($now)]);
            $row['id'] = (int) $this->pdo->lastInsertId();
            $this->replaceMeetings($row['id'], $row['meetings']);
            return Section::fromArray($row);
        });
    }

    public function updateSection(int $id, array $changes, string $now, ?callable $check = null): Section
    {
        if ($changes === [] || array_diff(array_keys($changes), self::SECTION_MUTABLE) !== []) {
            throw new InvalidArgumentException('Unsupported section change.');
        }
        return $this->write(function () use ($id, $changes, $now, $check): Section {
            $sections = $this->loadSections();
            $original = self::sectionById($sections, $id) ?? throw new HttpException(404, 'Section not found.');
            $row = array_replace($this->storedSection($original), $changes, ['updated_at' => $now]);
            if ($check !== null) {
                $check($row);
            }
            $section = Section::fromArray($row);
            $assignments = $this->loadAssignments();
            foreach ($assignments as $assignment) {
                if ($assignment['section_id'] !== $id) {
                    continue;
                }
                $conflict = self::conflictingSection($section, $assignment['faculty_id'], $sections, $assignments);
                if ($conflict !== null) {
                    throw new HttpException(409, 'Validation failed.', ['meetings' => 'Instructor ' . $assignment['faculty_id']
                        . ' already teaches ' . self::sectionLabel($conflict) . ' at an overlapping time.']);
                }
            }
            // OQ-3: a meeting change must not make any student's approved classes overlap (same lock/transaction).
            if (array_key_exists('meetings', $changes)) {
                $approved = $this->execute("SELECT student_id FROM enrollments WHERE section_id = ? AND status = 'approved' ORDER BY id", [$id])->fetchAll(PDO::FETCH_COLUMN);
                foreach ($approved as $studentId) {
                    $clash = self::clashWith($section, $this->studentSections((int) $studentId, ['approved'], $section->termId(), $sections));
                    if ($clash !== null) {
                        throw new HttpException(409, 'Validation failed.', ['meetings' => 'Student ' . (int) $studentId
                            . ' has an approved enrollment in ' . self::sectionLabel($clash) . ' at an overlapping time.']);
                    }
                }
            }
            $this->execute('UPDATE sections SET course_title = ?, units = ?, capacity = ?, delivery_mode = ?, updated_at = ? WHERE id = ?',
                [$row['course_title'], $row['units'], $row['capacity'], $row['delivery_mode'], self::sqlTime($now), $id]);
            if (array_key_exists('meetings', $changes)) {
                $this->replaceMeetings($id, $row['meetings']);
            }
            return $section;
        });
    }

    public function assignedFacultyIds(array $sectionIds): array
    {
        return $this->read(function () use ($sectionIds): array {
            $result = array_fill_keys($sectionIds, []);
            foreach ($this->loadAssignments() as $assignment) {
                if (array_key_exists($assignment['section_id'], $result)) {
                    $result[$assignment['section_id']][] = $assignment['faculty_id'];
                }
            }
            return $result;
        });
    }

    public function sectionIdsForFaculty(int $facultyId): array
    {
        return $this->read(function () use ($facultyId): array {
            $ids = [];
            foreach ($this->loadAssignments() as $assignment) {
                if ($assignment['faculty_id'] === $facultyId) {
                    $ids[] = $assignment['section_id'];
                }
            }
            sort($ids, SORT_NUMERIC);
            return $ids;
        });
    }

    public function assignInstructor(int $sectionId, int $facultyId, string $now, int $actorId): array
    {
        return $this->write(function () use ($sectionId, $facultyId, $now, $actorId): array {
            $sections = $this->loadSections();
            $section = self::sectionById($sections, $sectionId) ?? throw new HttpException(404, 'Section not found.');
            $assignments = $this->loadAssignments();
            $facultyIds = [];
            foreach ($assignments as $assignment) {
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
            $conflict = self::conflictingSection($section, $facultyId, $sections, $assignments);
            if ($conflict !== null) {
                throw new HttpException(409, 'Validation failed.', ['faculty_id' => 'This instructor already teaches '
                    . self::sectionLabel($conflict) . ' at an overlapping time.']);
            }
            $seq = $this->appendHistory($now, 'assigned', $sectionId, $facultyId, $actorId);
            $this->execute('INSERT INTO section_instructors (section_id, faculty_id, assigned_seq) VALUES (?, ?, ?)',
                [$sectionId, $facultyId, $seq]);
            $facultyIds[] = $facultyId;
            return ['section' => $section, 'faculty_ids' => $facultyIds];
        });
    }

    public function requestEnrollment(int $studentId, int $sectionId, string $now, int $actorId): Enrollment
    {
        return $this->write(function () use ($studentId, $sectionId, $now, $actorId): Enrollment {
            $sections = $this->loadSections();
            $section = self::sectionById($sections, $sectionId)
                ?? throw new HttpException(422, 'Validation failed.', ['section_id' => 'Unknown section.']);
            $term = self::termById($this->loadTerms(), $section->termId()) ?? throw new RuntimeException('Invalid stored section.');
            if (!$term->toArray()['enrollment_open']) {
                throw new HttpException(409, 'Validation failed.', ['section_id' => 'Enrollment is closed for this term.']);
            }
            if ($this->execute("SELECT 1 FROM enrollments WHERE student_id = ? AND section_id = ? AND status IN ('pending','approved')",
                [$studentId, $sectionId])->fetchColumn() !== false) {
                throw new HttpException(409, 'Validation failed.', ['section_id' => 'You already have a pending or approved request for this section.']);
            }
            if ($section->approvedCount() >= $section->capacity()) {
                throw new HttpException(409, 'Validation failed.', ['section_id' => 'This section is full.']);
            }
            $clash = self::clashWith($section, $this->studentSections($studentId, ['pending', 'approved'], $section->termId(), $sections));
            if ($clash !== null) {
                throw new HttpException(409, 'Validation failed.', ['section_id' => 'This section overlaps ' . self::sectionLabel($clash) . ' in your schedule.']);
            }
            $row = ['id' => 1, 'student_id' => $studentId, 'section_id' => $sectionId, 'status' => 'pending', 'requested_at' => $now,
                'decided_at' => null, 'decided_by' => null, 'dropped_at' => null, 'reason' => null];
            Enrollment::fromArray($row, $section->termId());
            $this->execute("INSERT INTO enrollments (student_id, section_id, status, requested_at) VALUES (?, ?, 'pending', ?)",
                [$studentId, $sectionId, self::sqlTime($now)]);
            $row['id'] = (int) $this->pdo->lastInsertId();
            $this->appendEnrollmentHistory($row['id'], $now, 'requested', $actorId, null);
            return Enrollment::fromArray($row, $section->termId());
        });
    }

    public function decideEnrollment(int $id, string $status, ?string $reason, string $now, int $actorId): Enrollment
    {
        if (!in_array($status, ['approved', 'rejected'], true) || ($status === 'rejected' && $reason === null)) {
            throw new InvalidArgumentException('Unsupported enrollment decision.');
        }
        return $this->write(function () use ($id, $status, $reason, $now, $actorId): Enrollment {
            $enrollment = $this->enrollmentById($id) ?? throw new HttpException(404, 'Enrollment not found.');
            if ($enrollment->status() !== 'pending') {
                throw new HttpException(409, 'Validation failed.', ['status' => 'Only a pending enrollment can be decided.']);
            }
            $sections = $this->loadSections();
            $section = self::sectionById($sections, $enrollment->sectionId()) ?? throw new RuntimeException('Invalid stored enrollment.');
            if ($status === 'approved') {
                if ($section->approvedCount() >= $section->capacity()) {
                    throw new HttpException(409, 'Validation failed.', ['status' => 'This section is full.']);
                }
                $clash = self::clashWith($section, $this->studentSections($enrollment->studentId(), ['approved'], $enrollment->termId(), $sections));
                if ($clash !== null) {
                    throw new HttpException(409, 'Validation failed.', ['status' => 'The student already has an approved class at an overlapping time: '
                        . self::sectionLabel($clash) . '.']);
                }
            }
            $stamp = max($now, $enrollment->row()['requested_at']);
            $row = array_replace($enrollment->row(), ['status' => $status, 'decided_at' => $stamp, 'decided_by' => $actorId, 'reason' => $reason]);
            $updated = Enrollment::fromArray($row, $enrollment->termId());
            if ($status === 'approved') {
                // sections.updated_at is deliberately untouched (it records catalog edits).
                $this->execute('UPDATE sections SET approved_count = approved_count + 1 WHERE id = ?', [$section->id()]);
            }
            $this->execute('UPDATE enrollments SET status = ?, decided_at = ?, decided_by = ?, reason = ? WHERE id = ?',
                [$status, self::sqlTime($stamp), $actorId, $reason, $id]);
            $this->appendEnrollmentHistory($id, $stamp, $status, $actorId, $reason);
            return $updated;
        });
    }

    public function dropEnrollment(int $id, int $studentId, string $now, int $actorId): Enrollment
    {
        return $this->write(function () use ($id, $studentId, $now, $actorId): Enrollment {
            $enrollment = $this->enrollmentById($id);
            if ($enrollment === null || $enrollment->studentId() !== $studentId) {
                throw new HttpException(404, 'Enrollment not found.');
            }
            if (!in_array($enrollment->status(), ['pending', 'approved'], true)) {
                throw new HttpException(409, 'Validation failed.', ['status' => 'Only a pending or approved enrollment can be dropped.']);
            }
            $term = self::termById($this->loadTerms(), $enrollment->termId()) ?? throw new RuntimeException('Invalid stored section.');
            if (!$term->toArray()['enrollment_open']) {
                throw new HttpException(409, 'Validation failed.', ['status' => 'Enrollment is closed for this term.']);
            }
            $row = $enrollment->row();
            $stamp = max($now, $row['decided_at'] ?? $row['requested_at']);
            $row = array_replace($row, ['status' => 'dropped', 'dropped_at' => $stamp]);
            $updated = Enrollment::fromArray($row, $enrollment->termId());
            if ($enrollment->status() === 'approved') {
                // Refuse rather than go negative on a legacy/corrupt count (same as the mock).
                $decrement = $this->execute('UPDATE sections SET approved_count = approved_count - 1 WHERE id = ? AND approved_count > 0', [$enrollment->sectionId()]);
                if ($decrement->rowCount() !== 1) {
                    throw new RuntimeException('Invalid enrollment store.');
                }
            }
            $this->execute("UPDATE enrollments SET status = 'dropped', dropped_at = ? WHERE id = ?", [self::sqlTime($stamp), $id]);
            $this->appendEnrollmentHistory($id, $stamp, 'dropped', $actorId, null);
            return $updated;
        });
    }

    public function findEnrollment(int $id): ?Enrollment
    {
        return $this->read(fn (): ?Enrollment => $this->enrollmentById($id));
    }

    public function searchEnrollments(array $filters, int $page, int $perPage): array
    {
        return $this->read(function () use ($filters, $page, $perPage): array {
            $where = [];
            $types = [];
            foreach (['status' => 'e.status', 'term_id' => 's.term_id', 'section_id' => 'e.section_id', 'student_id' => 'e.student_id'] as $key => $column) {
                if (isset($filters[$key])) {
                    $where[] = $column . ' = ?';
                    $types[] = $key === 'status' ? [(string) $filters[$key], PDO::PARAM_STR] : [(int) $filters[$key], PDO::PARAM_INT];
                }
            }
            $condition = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
            $from = ' FROM enrollments e JOIN sections s ON s.id = e.section_id' . $condition;
            $count = $this->pdo->prepare('SELECT COUNT(*)' . $from);
            foreach ($types as $index => [$value, $type]) {
                $count->bindValue($index + 1, $value, $type);
            }
            $count->execute();
            $total = (int) $count->fetchColumn();
            $list = $this->pdo->prepare(self::ENROLLMENT_SELECT . $condition . ' ORDER BY e.id LIMIT ? OFFSET ?');
            foreach ($types as $index => [$value, $type]) {
                $list->bindValue($index + 1, $value, $type);
            }
            $list->bindValue(count($types) + 1, $perPage, PDO::PARAM_INT);
            $list->bindValue(count($types) + 2, ($page - 1) * $perPage, PDO::PARAM_INT);
            $list->execute();
            return ['enrollments' => array_map(fn (array $row): Enrollment => self::enrollmentFromRow($row), $list->fetchAll(PDO::FETCH_ASSOC)),
                'total' => $total];
        });
    }

    public function approvedStudentIds(int $sectionId): array
    {
        return $this->read(fn (): array => array_map('intval', $this->execute(
            "SELECT DISTINCT student_id FROM enrollments WHERE section_id = ? AND status = 'approved' ORDER BY student_id",
            [$sectionId])->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function unassignInstructor(int $sectionId, int $facultyId, string $now, int $actorId): array
    {
        return $this->write(function () use ($sectionId, $facultyId, $now, $actorId): array {
            $section = self::sectionById($this->loadSections(), $sectionId)
                ?? throw new HttpException(404, 'Section not found.');
            $facultyIds = [];
            $found = false;
            foreach ($this->loadAssignments() as $assignment) {
                if ($assignment['section_id'] === $sectionId && $assignment['faculty_id'] === $facultyId) {
                    $found = true;
                } elseif ($assignment['section_id'] === $sectionId) {
                    $facultyIds[] = $assignment['faculty_id'];
                }
            }
            if (!$found) {
                throw new HttpException(404, 'Instructor assignment not found.');
            }
            $this->execute('DELETE FROM section_instructors WHERE section_id = ? AND faculty_id = ?', [$sectionId, $facultyId]);
            $this->appendHistory($now, 'unassigned', $sectionId, $facultyId, $actorId);
            return ['section' => $section, 'faculty_ids' => $facultyIds];
        });
    }

    private function loadTerms(): array
    {
        $rows = $this->execute('SELECT id, code, name, start_date, end_date, is_current, enrollment_open, created_at, updated_at FROM terms ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function (array $row): Term {
            $row['id'] = (int) $row['id'];
            if (!in_array((int) $row['is_current'], [0, 1], true)
                || !in_array((int) $row['enrollment_open'], [0, 1], true)) {
                throw new RuntimeException('Invalid stored term.');
            }
            $row['is_current'] = (int) $row['is_current'] === 1;
            $row['enrollment_open'] = (int) $row['enrollment_open'] === 1;
            $row['created_at'] = self::isoTime($row['created_at']);
            $row['updated_at'] = self::isoTime($row['updated_at']);
            return Term::fromArray($row);
        }, $rows);
    }

    private function loadSections(): array
    {
        $rows = $this->execute('SELECT id, term_id, course_code, course_title, units, section_code, capacity, approved_count, delivery_mode, created_at, updated_at FROM sections ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $meetings = [];
        foreach ($this->execute('SELECT section_id, position, day, start_time, end_time, room FROM section_meetings ORDER BY section_id, position')->fetchAll(PDO::FETCH_ASSOC) as $meeting) {
            if ((int) $meeting['position'] !== count($meetings[(int) $meeting['section_id']] ?? [])) {
                throw new RuntimeException('Invalid stored section.');
            }
            $meetings[(int) $meeting['section_id']][] = ['day' => $meeting['day'], 'start' => $meeting['start_time'],
                'end' => $meeting['end_time'], 'room' => $meeting['room']];
        }
        $sections = [];
        foreach ($rows as $row) {
            foreach (['id', 'term_id', 'units', 'capacity', 'approved_count'] as $field) {
                $row[$field] = (int) $row[$field];
            }
            $row = array_slice($row, 0, 9, true) + ['meetings' => $meetings[$row['id']] ?? []]
                + ['created_at' => self::isoTime($row['created_at']), 'updated_at' => self::isoTime($row['updated_at'])];
            $sections[] = Section::fromArray($row);
        }
        return $sections;
    }

    private function loadAssignments(): array
    {
        $rows = $this->execute('SELECT section_id, faculty_id FROM section_instructors ORDER BY assigned_seq')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $row): array => ['section_id' => (int) $row['section_id'],
            'faculty_id' => (int) $row['faculty_id']], $rows);
    }

    private function replaceMeetings(int $sectionId, array $meetings): void
    {
        $this->execute('DELETE FROM section_meetings WHERE section_id = ?', [$sectionId]);
        foreach ($meetings as $position => $meeting) {
            $this->execute('INSERT INTO section_meetings (section_id, position, day, start_time, end_time, room) VALUES (?, ?, ?, ?, ?, ?)',
                [$sectionId, $position, $meeting['day'], $meeting['start'], $meeting['end'], $meeting['room']]);
        }
    }

    private function clearCurrent(string $now): void
    {
        $this->execute('UPDATE terms SET is_current = 0, updated_at = ? WHERE is_current = 1', [self::sqlTime($now)]);
    }

    private function appendHistory(string $now, string $action, int $sectionId, int $facultyId, int $actorId): int
    {
        $seq = (int) $this->execute('SELECT last_seq FROM repository_locks WHERE name = ?', ['enrollment'])->fetchColumn() + 1;
        $this->execute('UPDATE repository_locks SET last_seq = ? WHERE name = ?', [$seq, 'enrollment']);
        $this->execute('INSERT INTO section_instructor_history (seq, at, action, section_id, faculty_id, actor_id) VALUES (?, ?, ?, ?, ?, ?)',
            [$seq, self::sqlTime($now), $action, $sectionId, $facultyId, $actorId]);
        return $seq;
    }

    private const ENROLLMENT_SELECT = 'SELECT e.id, e.student_id, e.section_id, e.status, e.requested_at, e.decided_at, e.decided_by, e.dropped_at, e.reason, s.term_id '
        . 'FROM enrollments e JOIN sections s ON s.id = e.section_id';

    private function enrollmentById(int $id): ?Enrollment
    {
        $row = $this->execute(self::ENROLLMENT_SELECT . ' WHERE e.id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::enrollmentFromRow($row);
    }

    private static function enrollmentFromRow(array $row): Enrollment
    {
        $stored = ['id' => (int) $row['id'], 'student_id' => (int) $row['student_id'], 'section_id' => (int) $row['section_id'],
            'status' => $row['status'], 'requested_at' => self::exactIsoTime($row['requested_at']),
            'decided_at' => self::exactIsoTime($row['decided_at']),
            'decided_by' => $row['decided_by'] === null ? null : (int) $row['decided_by'],
            'dropped_at' => self::exactIsoTime($row['dropped_at']), 'reason' => $row['reason']];
        return Enrollment::fromArray($stored, (int) $row['term_id']);
    }

    /** NULL passes through. Zero and impossible dates fail closed (exact calendar round trip, as in T-60 04a). */
    private static function exactIsoTime(mixed $time): ?string
    {
        if ($time === null) {
            return null;
        }
        $parsed = is_string($time) ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $time, new \DateTimeZone('UTC')) : false;
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $time || $parsed->format('Y') === '0000') {
            throw new RuntimeException('Invalid stored enrollment.');
        }
        return str_replace(' ', 'T', $time) . 'Z';
    }

    /** @param list<string> $statuses @return list<Section> the student's sections in the term with a row in $statuses (enrollment id order). */
    private function studentSections(int $studentId, array $statuses, int $termId, array $sections): array
    {
        $ids = $this->execute('SELECT e.section_id FROM enrollments e JOIN sections s ON s.id = e.section_id WHERE e.student_id = ? AND s.term_id = ? AND e.status IN ('
            . implode(',', array_fill(0, count($statuses), '?')) . ') ORDER BY e.id', array_merge([$studentId, $termId], $statuses))->fetchAll(PDO::FETCH_COLUMN);
        return array_map(static fn ($id): Section => self::sectionById($sections, (int) $id)
            ?? throw new RuntimeException('Invalid stored section.'), $ids);
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

    /** seq = existing rows for the enrollment + 1; runs inside the caller's enrollment lock. */
    private function appendEnrollmentHistory(int $enrollmentId, string $at, string $action, int $actorId, ?string $reason): void
    {
        $seq = (int) $this->execute('SELECT COUNT(*) FROM enrollment_history WHERE enrollment_id = ?', [$enrollmentId])->fetchColumn() + 1;
        $this->execute('INSERT INTO enrollment_history (enrollment_id, seq, at, action, actor_id, reason) VALUES (?, ?, ?, ?, ?, ?)',
            [$enrollmentId, $seq, self::sqlTime($at), $action, $actorId, $reason]);
    }

    private static function termById(array $terms, int $id): ?Term
    {
        foreach ($terms as $term) {
            if ($term->id() === $id) {
                return $term;
            }
        }
        return null;
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

    private function storedSection(Section $section): array
    {
        $row = $section->toArray();
        unset($row['remaining']);
        return $row;
    }

    private static function sectionLabel(Section $section): string
    {
        return $section->courseCode() . '-' . $section->sectionCode() . ' (section ' . $section->id() . ')';
    }

    private static function conflictingSection(Section $target, int $facultyId, array $sections, array $assignments): ?Section
    {
        usort($sections, static fn (Section $a, Section $b): int => $a->id() <=> $b->id());
        foreach ($sections as $other) {
            if ($other->id() === $target->id() || $other->termId() !== $target->termId()) {
                continue;
            }
            foreach ($assignments as $assignment) {
                if ($assignment['faculty_id'] !== $facultyId || $assignment['section_id'] !== $other->id()) {
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
        }
        return null;
    }

    private static function sqlTime(string $time): string
    {
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $time)) {
            throw new RuntimeException('Invalid catalog timestamp.');
        }
        return substr($time, 0, 10) . ' ' . substr($time, 11, 8);
    }

    private static function isoTime(string $time): string
    {
        // Shape alone admits zero dates (0000-00-00) and impossible days; require an exact calendar round trip.
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $time, new \DateTimeZone('UTC'));
        if (!preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $time)
            || $parsed === false || $parsed->format('Y-m-d H:i:s') !== $time || $parsed->format('Y') === '0000') {
            throw new RuntimeException('Invalid stored catalog row.');
        }
        return str_replace(' ', 'T', $time) . 'Z';
    }

    public function withEnrollmentLock(int $sectionId, callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Nested store operations are not supported.');
        }
        try {
            $this->pdo->beginTransaction();
            if ($this->execute('SELECT last_seq FROM repository_locks WHERE name = ? FOR UPDATE', ['enrollment'])->fetchColumn() === false) {
                throw new RuntimeException('Catalog repository lock missing.');
            }
            $ids = array_map('intval', $this->execute("SELECT DISTINCT student_id FROM enrollments WHERE section_id = ? AND status = 'approved' ORDER BY student_id FOR UPDATE", [$sectionId]) // L1: locking read, sees the latest committed approvals
                ->fetchAll(PDO::FETCH_COLUMN));
            // $fn runs on this same open transaction (the grade repository joins it and never begins or commits its own).
            $result = $fn($ids);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            $this->rollback();
            if ($error instanceof PDOException) {
                throw new RuntimeException('Catalog repository unavailable.');
            }
            throw $error;
        }
    }

    private function execute(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    private function write(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Nested store operations are not supported.');
        }
        try {
            $this->pdo->beginTransaction();
            if ($this->execute('SELECT last_seq FROM repository_locks WHERE name = ? FOR UPDATE', ['enrollment'])->fetchColumn() === false) {
                throw new RuntimeException('Catalog repository lock missing.');
            }
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            $this->rollback();
            if ($error instanceof PDOException) {
                throw new RuntimeException('Catalog repository unavailable.');
            }
            throw $error;
        }
    }

    private function read(callable $operation): mixed
    {
        $started = !$this->pdo->inTransaction();
        try {
            if ($started) {
                $this->pdo->beginTransaction();
            }
            $result = $operation();
            if ($started) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($started) {
                $this->rollback();
            }
            if ($error instanceof PDOException) {
                throw new RuntimeException('Catalog repository unavailable.');
            }
            throw $error;
        }
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            try {
                $this->pdo->rollBack();
            } catch (PDOException) {
                throw new RuntimeException('Catalog repository unavailable.');
            }
        }
    }
}
