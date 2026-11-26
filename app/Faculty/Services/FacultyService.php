<?php
declare(strict_types=1);

namespace App\Faculty\Services;

use App\Auth\Models\User;
use App\Auth\Repositories\UserRepositoryInterface;
use App\Auth\Services\AuthorizationService;
use App\Core\HttpException;
use App\Enrollment\Contracts\SectionDirectory;
use App\Faculty\Models\Faculty;
use App\Faculty\Repositories\FacultyRepositoryInterface;
use Closure;

/**
 * Team 5 faculty profiles (T-30). Routes are role-guarded; this service repeats the role checks.
 * Team 5 never writes users or roles: a profile links only to an account an admin already gave `faculty`.
 */
final class FacultyService
{
    private const STAFF = ['registrar', 'admin'];
    private const NOT_FOUND = 'Faculty profile not found.';
    private const NAME_ERROR = 'Use 1 to 70 characters without control characters.';
    private Closure $clock;

    public function __construct(
        private readonly FacultyRepositoryInterface $faculty,
        private readonly UserRepositoryInterface $users,
        private readonly AuthorizationService $authorization,
        private readonly SectionDirectory $sections,
        private readonly EmployeeNumberPolicy $numbers,
        ?callable $clock = null,
    ) {
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }

    public function list(User $actor, array $query): array
    {
        $this->authorization->requireRole($actor, self::STAFF);
        $errors = [];
        $search = $query['search'] ?? '';
        if (!is_string($search) || mb_strlen($search) > 100 || !mb_check_encoding($search, 'UTF-8')) {
            $errors['search'] = 'Use at most 100 characters.';
        }
        $filters = ['search' => is_string($search) ? $search : ''];
        if (isset($query['status'])) {
            in_array($query['status'], Faculty::STATUSES, true)
                ? $filters['status'] = $query['status']
                : $errors['status'] = 'Use one of: ' . implode(', ', Faculty::STATUSES) . '.';
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
        $result = $this->faculty->search($filters, $page, $perPage);
        return ['faculty' => array_map(static fn (Faculty $f): array => $f->toArray(), $result['faculty']),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $result['total']]];
    }

    public function get(User $actor, int $id): array
    {
        $this->authorization->requireRole($actor, self::STAFF);
        return ($this->faculty->findById($id) ?? throw new HttpException(404, self::NOT_FOUND))->toArray();
    }

    /** The caller's own profile, whatever its status (F16). */
    public function me(User $actor): array
    {
        $this->authorization->requireRole($actor, ['faculty']);
        return ($this->faculty->findByUserId($actor->id) ?? throw new HttpException(404, self::NOT_FOUND))->toArray();
    }

    /** Own sections only: the faculty id comes from the session user, never from the client (F18). */
    public function classes(User $actor, array $query): array
    {
        $this->authorization->requireRole($actor, ['faculty']);
        $termId = null;
        if (array_key_exists('term_id', $query)) {
            $termId = filter_var($query['term_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($termId === false) {
                throw new HttpException(422, 'Validation failed.', ['term_id' => 'Use a positive whole number.']);
            }
        }
        $profile = $this->faculty->findByUserId($actor->id) ?? throw new HttpException(404, self::NOT_FOUND);
        $classes = [];
        // shortcut: one findSection read per section; add a batch read if a faculty member teaches many sections
        foreach ($this->sections->sectionIdsForFaculty($profile->id()) as $sectionId) {
            $section = $this->sections->findSection($sectionId);
            if ($section !== null && ($termId === null || $section->termId === $termId)) {
                $classes[] = ['section_id' => $section->id, 'term_id' => $section->termId,
                    'course_code' => $section->courseCode, 'section_code' => $section->sectionCode];
            }
        }
        usort($classes, static fn (array $a, array $b): int => $a['section_id'] <=> $b['section_id']);
        return ['classes' => $classes];
    }

    public function create(User $actor, array $input): array
    {
        $this->authorization->requireRole($actor, self::STAFF);
        $errors = [];
        foreach (['user_id', 'employee_number', 'first_name', 'last_name', 'department'] as $field) {
            if (!array_key_exists($field, $input)) {
                $errors[$field] = 'This field is required.';
            }
        }
        $this->throwIfErrors($errors);
        $userId = is_int($input['user_id']) && $input['user_id'] > 0 ? $input['user_id'] : null;
        if ($userId === null) {
            $errors['user_id'] = 'Provide the id of an existing faculty account.';
        } else {
            // Before the repository lock: Team 2 has its own store and locks are never nested.
            $account = $this->users->findById($userId);
            if ($account === null || $account->status !== 'active' || !in_array('faculty', $account->roles, true)) {
                $errors['user_id'] = 'The account must exist, be active and hold the faculty role.';
            }
        }
        $number = $this->numbers->normalize($input['employee_number']);
        if ($number === null) {
            $errors['employee_number'] = 'Use the employee number format.';
        }
        $fields = $this->profileFields($input, $errors);
        $this->throwIfErrors($errors);
        $fields['status'] ??= 'active';
        return $this->faculty->create(['user_id' => $userId, 'employee_number' => $number] + $fields, $this->now(), $actor->id)->toArray();
    }

    public function update(User $actor, int $id, array $input): array
    {
        $this->authorization->requireRole($actor, self::STAFF);
        if ($input === []) {
            throw new HttpException(422, 'Validation failed.', ['body' => 'Provide at least one field to change.']);
        }
        $this->faculty->findById($id) ?? throw new HttpException(404, self::NOT_FOUND);
        $errors = [];
        $changes = $this->profileFields($input, $errors);
        $this->throwIfErrors($errors);
        return $this->faculty->update($id, $changes, $this->now(), $actor->id)->toArray();
    }

    /** Validates the profile fields present in $input (create checks the required ones first). */
    private function profileFields(array $input, array &$errors): array
    {
        $fields = [];
        foreach (['first_name' => [70, self::NAME_ERROR], 'last_name' => [70, self::NAME_ERROR],
            'department' => [100, 'Use 1 to 100 characters without control characters.']] as $name => [$max, $message]) {
            if (!array_key_exists($name, $input)) {
                continue;
            }
            $value = is_string($input[$name]) ? trim($input[$name]) : null;
            Faculty::validText($value, $max) ? $fields[$name] = $value : $errors[$name] = $message;
        }
        if (array_key_exists('status', $input)) {
            in_array($input['status'], Faculty::STATUSES, true)
                ? $fields['status'] = $input['status']
                : $errors['status'] = 'Use one of: ' . implode(', ', Faculty::STATUSES) . '.';
        }
        return $fields;
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
