<?php
declare(strict_types=1);

namespace App\Enrollment\Repositories;

use App\Core\HttpException;
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
        if (!preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $time)) {
            throw new RuntimeException('Invalid stored catalog row.');
        }
        return str_replace(' ', 'T', $time) . 'Z';
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
