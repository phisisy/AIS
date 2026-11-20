<?php
declare(strict_types=1);

namespace App\Enrollment\Services;

use App\Auth\Models\User;
use App\Auth\Services\AuthorizationService;
use App\Core\HttpException;
use App\Enrollment\Contracts\FacultyIdentityLookup;
use App\Enrollment\Contracts\UnavailableFacultyIdentity;
use App\Enrollment\Models\Section;
use App\Enrollment\Repositories\CatalogRepositoryInterface;
use Closure;
use stdClass;

final class SectionService
{
    private const STAFF = ['registrar', 'admin'];
    private const CREATE_FIELDS = ['term_id', 'course_code', 'course_title', 'units', 'section_code', 'capacity', 'meetings'];
    /** delivery_mode is optional on create and defaults to 'scheduled'; it is never inferred from an empty meeting list. */
    private const OPTIONAL_FIELDS = ['delivery_mode'];
    private const NO_MEETINGS = 'A scheduled section needs at least one meeting. Set delivery_mode to "tba" or "online_async" for a section without meetings.';
    private Closure $clock;
    private readonly FacultyIdentityLookup $identities;

    public function __construct(
        private readonly CatalogRepositoryInterface $catalog,
        private readonly AuthorizationService $authorization,
        ?FacultyIdentityLookup $identities = null,
        ?callable $clock = null,
    ) {
        $this->identities = $identities ?? new UnavailableFacultyIdentity();
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }

    /** Authentication is enforced by the route guard; every signed-in user can read. */
    public function list(array $query): array
    {
        $errors = [];
        $filters = [];
        $search = $query['search'] ?? '';
        if (!is_string($search) || !mb_check_encoding($search, 'UTF-8') || mb_strlen($search, 'UTF-8') > 100) {
            $errors['search'] = 'Use at most 100 characters.';
        } else {
            $filters['search'] = $search;
        }
        if (array_key_exists('term_id', $query)) {
            $termId = filter_var($query['term_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $termId === false ? $errors['term_id'] = 'Use a positive whole number.' : $filters['term_id'] = $termId;
        }
        if (array_key_exists('course', $query)) {
            $course = is_string($query['course']) ? strtoupper(trim($query['course'])) : '';
            preg_match('/\A[A-Z0-9][A-Z0-9-]{1,19}\z/', $course) === 1
                ? $filters['course_code'] = $course : $errors['course'] = 'Use 2 to 20 characters: A-Z, 0-9 and hyphen.';
        }
        $page = filter_var($query['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1_000_000]]);
        $perPage = filter_var($query['per_page'] ?? '20', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($page === false) {
            $errors['page'] = 'Use a whole number from 1 to 1000000.';
        }
        if ($perPage === false) {
            $errors['per_page'] = 'Use a whole number from 1 to 100.';
        }
        $this->throwIfErrors($errors);
        $result = $this->catalog->searchSections($filters, $page, $perPage);
        return ['sections' => $this->present($result['sections']),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $result['total']]];
    }

    public function get(int $id): array
    {
        return $this->present([$this->catalog->findSection($id) ?? throw new HttpException(404, 'Section not found.')])[0];
    }

    public function create(User $actor, array $input): array
    {
        $this->authorization->requireRole($actor, self::STAFF);
        $this->allowFields($input, [...self::CREATE_FIELDS, ...self::OPTIONAL_FIELDS]);
        $errors = [];
        foreach (self::CREATE_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                $errors[$field] = 'This field is required.';
            }
        }
        $this->throwIfErrors($errors);
        $fields = $this->fields($input, $errors) + ['delivery_mode' => 'scheduled'];
        // Skip when the meetings or the mode were already rejected (including per-item meetings.N.* errors).
        $meetingErrors = preg_grep('/\Ameetings(\.|\z)/', array_map('strval', array_keys($errors)));
        if ($meetingErrors === [] && !isset($errors['delivery_mode'])) {
            self::requireMeetingsForMode($fields, $errors);
        }
        $this->throwIfErrors($errors);
        return $this->catalog->createSection($fields, $this->now())->withInstructors([]);
    }

    public function update(User $actor, int $id, array $input): array
    {
        $this->authorization->requireRole($actor, self::STAFF);
        $this->allowFields($input, ['course_title', 'units', 'capacity', 'delivery_mode', 'meetings']);
        if ($input === []) {
            throw new HttpException(422, 'Validation failed.', ['body' => 'Provide at least one field to change.']);
        }
        $this->catalog->findSection($id) ?? throw new HttpException(404, 'Section not found.');
        $errors = [];
        $changes = $this->fields($input, $errors);
        $this->throwIfErrors($errors);
        // Read approved_count only under the write lock, including for partial PATCHes.
        $check = static function (array $row): void {
            // A PATCH may change only the mode or only the meetings, so the pairing is checked on the merged row.
            $errors = [];
            self::requireMeetingsForMode($row, $errors);
            if ($errors !== []) {
                throw new HttpException(422, 'Validation failed.', $errors);
            }
            if ($row['capacity'] < $row['approved_count']) {
                throw new HttpException(409, 'Validation failed.',
                    ['capacity' => 'Capacity cannot be below the approved count (' . $row['approved_count'] . ').']);
            }
        };
        return $this->present([$this->catalog->updateSection($id, $changes, $this->now(), $check)])[0];
    }

    public function assignInstructor(User $actor, int $sectionId, array $input): array
    {
        $this->authorization->requireRole($actor, self::STAFF);
        $this->allowFields($input, ['faculty_id']);
        $this->catalog->findSection($sectionId) ?? throw new HttpException(404, 'Section not found.');
        if (!array_key_exists('faculty_id', $input)) {
            throw new HttpException(422, 'Validation failed.', ['faculty_id' => 'This field is required.']);
        }
        $facultyId = $input['faculty_id'];
        if (!is_int($facultyId) || $facultyId < 1) {
            throw new HttpException(422, 'Validation failed.', ['faculty_id' => 'Use a positive whole number.']);
        }
        try {
            $identity = $this->identities->find($facultyId);
        } catch (\Throwable) {
            $identity = null;
        }
        if ($identity === null || $identity->facultyId !== $facultyId || !$identity->active) {
            throw new HttpException(422, 'Validation failed.', ['faculty_id' => 'Unknown or inactive faculty member.']);
        }
        $result = $this->catalog->assignInstructor($sectionId, $facultyId, $this->now(), $actor->id);
        return $this->presentAssignment($result);
    }

    public function unassignInstructor(User $actor, int $sectionId, int $facultyId): array
    {
        $this->authorization->requireRole($actor, self::STAFF);
        $result = $this->catalog->unassignInstructor($sectionId, $facultyId, $this->now(), $actor->id);
        return $this->presentAssignment($result);
    }

    /** @param list<Section> $sections */
    private function present(array $sections): array
    {
        if ($sections === []) {
            return [];
        }
        $bySection = $this->catalog->assignedFacultyIds(array_map(static fn (Section $section): int => $section->id(), $sections));
        $ids = [];
        foreach ($bySection as $facultyIds) {
            foreach ($facultyIds as $id) {
                $ids[$id] = $id;
            }
        }
        $names = $this->names(array_values($ids));
        return array_map(static function (Section $section) use ($bySection, $names): array {
            $instructors = [];
            foreach ($bySection[$section->id()] ?? [] as $id) {
                $instructors[] = ['faculty_id' => $id, 'name' => $names[$id] ?? null];
            }
            return $section->withInstructors($instructors);
        }, $sections);
    }

    /** Uses the committed transaction result, avoiding a second store read for assignment responses. */
    private function presentAssignment(array $result): array
    {
        $names = $this->names($result['faculty_ids']);
        $instructors = [];
        foreach ($result['faculty_ids'] as $id) {
            $instructors[] = ['faculty_id' => $id, 'name' => $names[$id] ?? null];
        }
        return $result['section']->withInstructors($instructors);
    }

    /** @param list<int> $ids @return array<int,string> */
    private function names(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        try {
            $found = $this->identities->findMany(array_values(array_unique($ids)));
        } catch (\Throwable) {
            return [];
        }
        $names = [];
        foreach ($ids as $id) {
            if (isset($found[$id]) && $found[$id] instanceof \App\Enrollment\Contracts\FacultyIdentity
                && $found[$id]->facultyId === $id) {
                $names[$id] = $found[$id]->displayName;
            }
        }
        return $names;
    }

    /** Format validation only; term existence and uniqueness belong to the repository lock. */
    private function fields(array $input, array &$errors): array
    {
        $fields = [];
        if (array_key_exists('term_id', $input)) {
            is_int($input['term_id']) && $input['term_id'] >= 1
                ? $fields['term_id'] = $input['term_id'] : $errors['term_id'] = 'Unknown term.';
        }
        foreach (['course_code' => 20, 'section_code' => 10] as $field => $max) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $value = is_string($input[$field]) ? strtoupper(trim($input[$field])) : '';
            $min = $field === 'course_code' ? 2 : 1;
            $pattern = $field === 'course_code' ? '/\A[A-Z0-9][A-Z0-9-]{1,19}\z/' : '/\A[A-Z0-9][A-Z0-9-]{0,9}\z/';
            preg_match($pattern, $value) === 1 ? $fields[$field] = $value
                : $errors[$field] = "Use {$min} to {$max} characters: A-Z, 0-9 and hyphen.";
        }
        if (array_key_exists('course_title', $input)) {
            $title = $this->text($input['course_title'], 150);
            $title === null ? $errors['course_title'] = 'Use 1 to 150 characters without control characters.' : $fields['course_title'] = $title;
        }
        foreach (['units' => 12, 'capacity' => 1000] as $field => $max) {
            if (array_key_exists($field, $input)) {
                is_int($input[$field]) && $input[$field] >= 1 && $input[$field] <= $max
                    ? $fields[$field] = $input[$field] : $errors[$field] = "Use a whole number from 1 to {$max}.";
            }
        }
        if (array_key_exists('delivery_mode', $input)) {
            in_array($input['delivery_mode'], Section::DELIVERY_MODES, true)
                ? $fields['delivery_mode'] = $input['delivery_mode'] : $errors['delivery_mode'] = 'Use one of: scheduled, tba, online_async.';
        }
        if (array_key_exists('meetings', $input)) {
            $fields['meetings'] = $this->meetings($input['meetings'], $errors);
        }
        return $fields;
    }

    /** Ordinary scheduled sections need a meeting; only an explicit tba/online_async mode may have none. */
    private static function requireMeetingsForMode(array $row, array &$errors): void
    {
        if ($row['delivery_mode'] === 'scheduled' && $row['meetings'] === []) {
            $errors['meetings'] = self::NO_MEETINGS;
        }
    }

    /** Request preserves meeting objects as stdClass; JSON arrays are never meeting objects. */
    private function meetings(mixed $input, array &$errors): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) > 14) {
            $errors['meetings'] = 'Provide a list of at most 14 meetings.';
            return [];
        }
        $meetings = [];
        foreach ($input as $i => $item) {
            if (!$item instanceof stdClass) {
                $errors["meetings.{$i}"] = 'Provide an object with day, start, end and room.';
                continue;
            }
            $row = get_object_vars($item);
            $keys = ['day', 'start', 'end', 'room'];
            if (array_diff(array_keys($row), $keys) !== []) {
                $errors["meetings.{$i}"] = 'Unknown field.';
                continue;
            }
            if (array_diff($keys, array_keys($row)) !== []) {
                $errors["meetings.{$i}"] = 'Provide exactly day, start, end and room.';
                continue;
            }
            $valid = true;
            if (!in_array($row['day'], Section::DAYS, true)) {
                $errors["meetings.{$i}.day"] = 'Use one of: MON, TUE, WED, THU, FRI, SAT, SUN.';
                $valid = false;
            }
            $validTimes = true;
            foreach (['start', 'end'] as $field) {
                if (!is_string($row[$field]) || preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $row[$field]) !== 1) {
                    $errors["meetings.{$i}.{$field}"] = 'Use a time in HH:MM format from 00:00 to 23:59.';
                    $validTimes = false;
                }
            }
            if ($validTimes && $row['start'] >= $row['end']) {
                $errors["meetings.{$i}.end"] = 'The end time must be after the start time.';
                $validTimes = false;
            }
            $room = $this->text($row['room'], 30);
            if ($room === null) {
                $errors["meetings.{$i}.room"] = 'Use 1 to 30 characters without control characters.';
                $valid = false;
            }
            if ($valid && $validTimes) {
                $meetings[$i] = ['day' => $row['day'], 'start' => $row['start'], 'end' => $row['end'], 'room' => $room];
            }
        }
        foreach ($meetings as $i => $meeting) {
            foreach ($meetings as $j => $other) {
                if ($j > $i && $meeting['day'] === $other['day'] && $meeting['start'] < $other['end'] && $other['start'] < $meeting['end']) {
                    $errors['meetings'] = "Meetings {$i} and {$j} overlap.";
                    return [];
                }
            }
        }
        usort($meetings, static fn (array $a, array $b): int => array_search($a['day'], Section::DAYS, true) <=> array_search($b['day'], Section::DAYS, true)
            ?: strcmp($a['start'], $b['start']));
        return $meetings;
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : null;
        return $value === null || $value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $max
            || preg_match('/[\p{Cc}\p{Cf}]/u', $value) ? null : $value;
    }

    private function allowFields(array $input, array $allowed): void
    {
        $this->throwIfErrors(array_fill_keys(array_diff(array_keys($input), $allowed), 'Unknown field.'));
    }

    private function throwIfErrors(array $errors): void
    {
        if ($errors !== []) {
            throw new HttpException(422, 'Validation failed.', $errors);
        }
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', ($this->clock)());
    }
}
