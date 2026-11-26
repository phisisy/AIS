<?php
declare(strict_types=1);

namespace App\Students\Services;

use App\Auth\Models\User;
use App\Auth\Repositories\UserRepositoryInterface;
use App\Auth\Services\AuthorizationService;
use App\Core\HttpException;
use App\Students\Contracts\FacultyStudentVisibility;
use App\Students\Contracts\ProgramCatalogInterface;
use App\Students\Models\Student;
use App\Students\Repositories\StudentRepositoryInterface;
use Closure;

/**
 * Team 3 student records (T-10). Routes are role-guarded (T-02); this service repeats the
 * role checks and enforces record ownership and B-07 faculty visibility. Anything a caller
 * may not see is reported exactly like a missing record (404).
 */
final class StudentService
{
    private const STAFF = ['registrar', 'admin'];
    private const NOT_FOUND = 'Student not found.';
    private Closure $clock;

    public function __construct(
        private readonly StudentRepositoryInterface $students,
        private readonly ProgramCatalogInterface $programs,
        private readonly UserRepositoryInterface $users,
        private readonly AuthorizationService $authorization,
        private readonly FacultyStudentVisibility $facultyVisibility,
        private readonly StudentNumberPolicy $numbers,
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
        if (isset($query['program_code'])) {
            $code = is_string($query['program_code']) ? strtoupper(trim($query['program_code'])) : '';
            $code !== '' && strlen($code) <= 20 ? $filters['program_code'] = $code : $errors['program_code'] = 'Invalid program code.';
        }
        if (isset($query['academic_status'])) {
            in_array($query['academic_status'], Student::STATUSES, true)
                ? $filters['academic_status'] = $query['academic_status']
                : $errors['academic_status'] = 'Use one of: ' . implode(', ', Student::STATUSES) . '.';
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
        $result = $this->students->search($filters, $page, $perPage);
        return ['students' => array_map(static fn (Student $s): array => $s->toArray(), $result['students']),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $result['total']]];
    }

    public function me(User $actor): array
    {
        $this->authorization->requireRole($actor, ['student']);
        return ($this->students->findByUserId($actor->id) ?? throw new HttpException(404, self::NOT_FOUND))->toArray();
    }

    /** Staff: full record. Own account: full record. Faculty: B-07 summary if verified. Otherwise 404. */
    public function get(User $actor, int $id): array
    {
        $this->authorization->requireRole($actor, [...self::STAFF, 'student', 'faculty']);
        $student = $this->students->findById($id);
        if ($student !== null && $this->authorization->hasAnyRole($actor, self::STAFF)) {
            return $student->toArray();
        }
        // Own-record access is a student privilege (owner decision 3): an account that lost the
        // student role keeps its profile in storage but can no longer read it through this API.
        if ($student !== null && $student->userId() === $actor->id && $this->authorization->hasAnyRole($actor, ['student'])) {
            return $student->toArray();
        }
        if ($this->authorization->hasAnyRole($actor, ['faculty'])) {
            // Asked whether or not the record exists, so response time doesn't reveal which ids are real.
            $sections = $this->verifiedSections($actor->id, $id);
            if ($student !== null && $sections !== []) {
                return $student->toFacultySummary($sections);
            }
        }
        throw new HttpException(404, self::NOT_FOUND);
    }

    public function create(User $actor, array $input): array
    {
        $this->authorization->requireRole($actor, self::STAFF);
        $errors = [];
        foreach (['user_id', 'student_number', 'first_name', 'last_name', 'program_code', 'year_level'] as $field) {
            if (!array_key_exists($field, $input)) {
                $errors[$field] = 'This field is required.';
            }
        }
        $this->throwIfErrors($errors);
        $userId = is_int($input['user_id']) && $input['user_id'] > 0 ? $input['user_id'] : null;
        if ($userId === null) {
            $errors['user_id'] = 'Provide the id of an existing student account.';
        } else {
            $account = $this->users->findById($userId);
            if ($account === null || $account->status !== 'active' || !in_array('student', $account->roles, true)) {
                $errors['user_id'] = 'The account must exist, be active and hold the student role.';
            }
        }
        $number = $this->numbers->normalize($input['student_number']);
        if ($number === null) {
            $errors['student_number'] = 'Use the institutional student number format.';
        }
        $fields = $this->profileFields($input, $errors, null);
        $this->throwIfErrors($errors);
        $fields['academic_status'] ??= 'active';
        $fields['middle_name'] ??= null;
        $fields['admission_year'] ??= null;
        return $this->students->create(['user_id' => $userId, 'student_number' => $number] + $fields, $this->now(), $actor->id)->toArray();
    }

    public function update(User $actor, int $id, array $input): array
    {
        $this->authorization->requireRole($actor, self::STAFF);
        if ($input === []) {
            throw new HttpException(422, 'Validation failed.', ['body' => 'Provide at least one field to change.']);
        }
        $current = $this->students->findById($id) ?? throw new HttpException(404, self::NOT_FOUND);
        $errors = [];
        $changes = $this->profileFields($input, $errors, $current);
        $this->throwIfErrors($errors);
        // Re-check year level against the program on the row as it will be written (inside the lock),
        // so two concurrent PATCHes can't combine into an invalid program/year pair.
        $check = function (array $row): void {
            $program = $this->programs->find($row['program_code']);
            if ($program === null || $row['year_level'] > $program->maxYearLevel) {
                throw new HttpException(409, 'Validation failed.', ['year_level' => 'The year level no longer fits the program; reload and retry.']);
            }
        };
        return $this->students->update($id, $changes, $this->now(), $check, $actor->id)->toArray();
    }

    /** Validates the profile fields present in $input (all optional here; create checks required ones). */
    private function profileFields(array $input, array &$errors, ?Student $current): array
    {
        $fields = [];
        foreach (['first_name' => false, 'last_name' => false, 'middle_name' => true] as $name => $nullable) {
            if (!array_key_exists($name, $input)) {
                continue;
            }
            $value = $input[$name];
            if ($nullable && $value === null) {
                $fields[$name] = null;
                continue;
            }
            $value = is_string($value) ? trim($value) : null;
            // \p{Cc}\p{Cf}: ASCII/C1 controls plus format characters (bidi overrides, zero-width).
            if ($value === null || $value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 100
                || preg_match('/[\p{Cc}\p{Cf}]/u', $value)) {
                $errors[$name] = 'Use 1 to 100 characters without control characters.';
                continue;
            }
            $fields[$name] = $value;
        }
        if (array_key_exists('academic_status', $input)) {
            in_array($input['academic_status'], Student::STATUSES, true)
                ? $fields['academic_status'] = $input['academic_status']
                : $errors['academic_status'] = 'Use one of: ' . implode(', ', Student::STATUSES) . '.';
        }
        if (array_key_exists('admission_year', $input)) {
            $year = $input['admission_year'];
            $max = (int) gmdate('Y', ($this->clock)()) + 1;
            $year === null || (is_int($year) && $year >= 1900 && $year <= $max)
                ? $fields['admission_year'] = $year
                : $errors['admission_year'] = "Use a year from 1900 to {$max}, or null.";
        }
        $programCode = $current?->programCode();
        if (array_key_exists('program_code', $input)) {
            $programCode = is_string($input['program_code']) ? strtoupper(trim($input['program_code'])) : '';
            $fields['program_code'] = $programCode;
        }
        $program = $programCode === null || $programCode === '' ? null : $this->programs->find($programCode);
        if (array_key_exists('program_code', $input) && $program === null) {
            $errors['program_code'] = 'Unknown program.';
        }
        // year_level is always checked against the program that applies after the change.
        $yearLevel = array_key_exists('year_level', $input) ? $input['year_level'] : $current?->yearLevel();
        if (array_key_exists('year_level', $input) || isset($fields['program_code'])) {
            if (!is_int($yearLevel) || $yearLevel < 1 || ($program !== null && $yearLevel > $program->maxYearLevel)) {
                $errors['year_level'] = $program === null ? 'Use a whole number of at least 1.'
                    : "Use a whole number from 1 to {$program->maxYearLevel} for this program.";
            } elseif (array_key_exists('year_level', $input)) {
                $fields['year_level'] = $yearLevel;
            }
        }
        return $fields;
    }

    /** Fails closed: any error from the visibility source means "not visible". */
    private function verifiedSections(int $facultyUserId, int $studentId): array
    {
        try {
            $sections = $this->facultyVisibility->sharedSections($facultyUserId, $studentId);
        } catch (\Throwable $exception) {
            error_log('AIS faculty visibility check failed: ' . (preg_match('/\A[A-Za-z0-9_\\\\]+\z/', $exception::class) ? $exception::class : 'Throwable'));
            return [];
        }
        $verified = [];
        foreach (is_array($sections) ? $sections : [] as $section) {
            if (!is_array($section) || !is_int($section['section_id'] ?? null) || !is_string($section['section_code'] ?? null)
                || !is_string($section['enrollment_status'] ?? null)) {
                return []; // malformed answer: do not trust any of it
            }
            // Second line of defence: only approved enrollments make a student visible.
            if ($section['enrollment_status'] === 'approved') {
                $verified[] = ['section_id' => $section['section_id'], 'section_code' => $section['section_code'],
                    'enrollment_status' => 'approved'];
            }
        }
        return $verified;
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
