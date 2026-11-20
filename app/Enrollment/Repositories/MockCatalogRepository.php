<?php
declare(strict_types=1);

namespace App\Enrollment\Repositories;

use App\Core\HttpException;
use App\Core\JsonStore;
use App\Enrollment\Models\Section;
use App\Enrollment\Models\Term;
use InvalidArgumentException;
use RuntimeException;

/** Terms and sections share one store and one exclusive lock per write. */
final class MockCatalogRepository implements CatalogRepositoryInterface
{
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
        if (array_diff(array_keys($data), ['terms', 'sections', 'assignments', 'assignment_history']) !== []) {
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
        return $snapshot;
    }
}
