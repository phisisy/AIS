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
            return $section;
        });
    }

    public function updateSection(int $id, array $changes, string $now, ?callable $check = null): Section
    {
        if ($changes === [] || array_diff(array_keys($changes), self::SECTION_MUTABLE) !== []) {
            throw new InvalidArgumentException('Unsupported section change.');
        }
        return $this->store->transaction(function (array &$data) use ($id, $changes, $now, $check): Section {
            $this->snapshot($data);
            foreach ($data['sections'] ?? [] as $index => $row) {
                if ($row['id'] === $id) {
                    $merged = array_replace($row, $changes, ['updated_at' => $now]);
                    if ($check !== null) {
                        $check($merged);
                    }
                    $section = Section::fromArray($merged);
                    // Persist the stored row, never the response's computed remaining field.
                    $data['sections'][$index] = $merged;
                    return $section;
                }
            }
            throw new HttpException(404, 'Section not found.');
        });
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
        if (array_diff(array_keys($data), ['terms', 'sections']) !== []) {
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
        return $snapshot;
    }
}
