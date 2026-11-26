<?php
declare(strict_types=1);

namespace App\Faculty\Repositories;

use App\Core\HttpException;
use App\Core\JsonStore;
use App\Faculty\Models\Faculty;
use App\Faculty\Services\EmployeeNumberPolicy;
use InvalidArgumentException;
use RuntimeException;

/** Development storage. One file, one exclusive lock per write; history is one global, gap-free, replay-checked list. */
final class MockFacultyRepository implements FacultyRepositoryInterface
{
    private const MUTABLE = ['first_name', 'last_name', 'department', 'status'];
    /** Fields whose changes are kept as history. Name edits are corrections, not history. */
    private const TRACKED = ['status', 'department'];
    private const HISTORY_KEYS = ['seq', 'at', 'faculty_id', 'field', 'from', 'to', 'actor_id'];

    public function __construct(private readonly JsonStore $store, private readonly ?EmployeeNumberPolicy $numbers = null)
    {
    }

    public function findById(int $id): ?Faculty
    {
        foreach ($this->snapshot($this->store->read())[0] as $faculty) {
            if ($faculty->id() === $id) {
                return $faculty;
            }
        }
        return null;
    }

    public function findByUserId(int $userId): ?Faculty
    {
        foreach ($this->snapshot($this->store->read())[0] as $faculty) {
            if ($faculty->userId() === $userId) {
                return $faculty;
            }
        }
        return null;
    }

    public function findByIds(array $ids): array
    {
        $found = [];
        foreach ($this->snapshot($this->store->read())[0] as $faculty) {
            if (in_array($faculty->id(), $ids, true)) {
                $found[$faculty->id()] = $faculty;
            }
        }
        return $found;
    }

    public function search(array $filters, int $page, int $perPage): array
    {
        $needle = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $matches = array_values(array_filter($this->snapshot($this->store->read())[0], static function (Faculty $f) use ($filters, $needle): bool {
            $row = $f->toArray();
            if (isset($filters['status']) && $row['status'] !== $filters['status']) {
                return false;
            }
            return $needle === '' || str_contains(mb_strtolower($row['employee_number']), $needle)
                || str_contains(mb_strtolower($row['first_name']), $needle) || str_contains(mb_strtolower($row['last_name']), $needle);
        }));
        usort($matches, static fn (Faculty $a, Faculty $b): int => $a->id() <=> $b->id());
        return ['faculty' => array_slice($matches, ($page - 1) * $perPage, $perPage), 'total' => count($matches)];
    }

    public function create(array $fields, string $now, int $actorId): Faculty
    {
        return $this->store->transaction(function (array &$data) use ($fields, $now, $actorId): Faculty {
            [$all, $history] = $this->snapshot($data);
            $errors = [];
            foreach ($all as $existing) {
                if ($existing->userId() === $fields['user_id']) {
                    $errors['user_id'] = 'This account already has a faculty profile.';
                }
                if ($existing->employeeNumber() === $fields['employee_number']) {
                    $errors['employee_number'] = 'This employee number is already in use.';
                }
            }
            if ($errors !== []) {
                throw new HttpException(409, 'Validation failed.', $errors);
            }
            $id = array_reduce($all, static fn (int $max, Faculty $f): int => max($max, $f->id()), 0) + 1;
            $row = ['id' => $id, 'user_id' => $fields['user_id'], 'employee_number' => $fields['employee_number'],
                'first_name' => $fields['first_name'], 'last_name' => $fields['last_name'], 'department' => $fields['department'],
                'status' => $fields['status'], 'created_at' => $now, 'updated_at' => $now];
            $faculty = Faculty::fromArray($row, $this->numbers);
            $history = self::appendHistory($history, $id, array_fill_keys(self::TRACKED, null), $row, $now, $actorId);
            $data = ['faculty' => array_merge(array_map(static fn (Faculty $f): array => $f->toArray(), $all), [$row]), 'history' => $history];
            return $faculty;
        });
    }

    public function update(int $id, array $changes, string $now, int $actorId): Faculty
    {
        if ($changes === [] || array_diff(array_keys($changes), self::MUTABLE) !== []) {
            throw new InvalidArgumentException('Unsupported faculty change.');
        }
        return $this->store->transaction(function (array &$data) use ($id, $changes, $now, $actorId): Faculty {
            [$all, $history] = $this->snapshot($data);
            $rows = array_map(static fn (Faculty $f): array => $f->toArray(), $all);
            foreach ($rows as $index => $row) {
                if ($row['id'] === $id) {
                    $merged = array_replace($row, $changes, ['updated_at' => $now]);
                    $updated = Faculty::fromArray($merged, $this->numbers);
                    $rows[$index] = $merged;
                    $data = ['faculty' => $rows, 'history' => self::appendHistory($history, $id, $row, $merged, $now, $actorId)];
                    return $updated;
                }
            }
            throw new HttpException(404, 'Faculty profile not found.');
        });
    }

    /** One entry per tracked field whose value changed; seq is global and gap-free. */
    private static function appendHistory(array $history, int $id, array $before, array $after, string $now, int $actorId): array
    {
        foreach (self::TRACKED as $field) {
            if ($before[$field] !== $after[$field]) {
                $history[] = ['seq' => count($history) + 1, 'at' => $now, 'faculty_id' => $id, 'field' => $field,
                    'from' => $before[$field], 'to' => $after[$field], 'actor_id' => $actorId];
            }
        }
        return $history;
    }

    private static function validValue(string $field, mixed $value): bool
    {
        return $field === 'status' ? in_array($value, Faculty::STATUSES, true) : Faculty::validText($value, 100);
    }

    /**
     * Validates the whole store (corruption fails closed). A missing/empty file has no profiles.
     * @return array{0: list<Faculty>, 1: list<array>}
     */
    private function snapshot(array $data): array
    {
        if ($data === []) {
            return [[], []];
        }
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== ['faculty', 'history'] || !is_array($data['faculty']) || !array_is_list($data['faculty'])
            || !is_array($data['history']) || !array_is_list($data['history'])) {
            throw new RuntimeException('Invalid faculty store.');
        }
        $all = [];
        $seen = ['id' => [], 'user_id' => [], 'employee_number' => []];
        foreach ($data['faculty'] as $row) {
            $faculty = Faculty::fromArray($row, $this->numbers);
            foreach (array_keys($seen) as $unique) {
                if (isset($seen[$unique][$faculty->toArray()[$unique]])) {
                    throw new RuntimeException('Invalid faculty store.');
                }
                $seen[$unique][$faculty->toArray()[$unique]] = true;
            }
            $all[] = $faculty;
        }
        $last = [];
        foreach ($data['history'] as $index => $entry) {
            if (!is_array($entry) || array_keys($entry) !== self::HISTORY_KEYS || $entry['seq'] !== $index + 1
                || !is_string($entry['at']) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $entry['at']) !== 1
                || !in_array($entry['field'], self::TRACKED, true)
                || !is_int($entry['faculty_id']) || !isset($seen['id'][$entry['faculty_id']])
                || !is_int($entry['actor_id']) || $entry['actor_id'] < 1
                || !self::validValue($entry['field'], $entry['to'])
                || ($entry['from'] !== null && !self::validValue($entry['field'], $entry['from']))) {
                throw new RuntimeException('Invalid faculty store.');
            }
            // Replay: each entry continues the previous one for the same (profile, field); the first starts at null.
            $key = $entry['faculty_id'] . '|' . $entry['field'];
            if ($entry['from'] !== ($last[$key] ?? null)) {
                throw new RuntimeException('Invalid faculty store.');
            }
            $last[$key] = $entry['to'];
        }
        foreach ($all as $faculty) {
            foreach (self::TRACKED as $field) {
                if (($last[$faculty->id() . '|' . $field] ?? null) !== $faculty->toArray()[$field]) {
                    throw new RuntimeException('Invalid faculty store.');
                }
            }
        }
        return [$all, $data['history']];
    }
}
