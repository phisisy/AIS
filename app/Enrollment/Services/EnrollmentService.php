<?php
declare(strict_types=1);

namespace App\Enrollment\Services;

use App\Auth\Models\User;
use App\Auth\Services\AuthorizationService;
use App\Core\HttpException;
use App\Enrollment\Models\Enrollment;
use App\Enrollment\Repositories\CatalogRepositoryInterface;
use App\Students\Contracts\StudentDirectory;
use App\Students\Contracts\StudentSummary;
use Closure;

/** T-22: enrollment requests, decisions, drops and the student schedule. Atomic rules live in the repository lock. */
final class EnrollmentService
{
    private const STAFF = ['registrar', 'admin'];
    private Closure $clock;

    public function __construct(
        private readonly CatalogRepositoryInterface $catalog,
        private readonly StudentDirectory $students,
        private readonly SectionService $sections,
        private readonly AuthorizationService $authorization,
        ?callable $clock = null,
    ) {
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }

    public function request(User $actor, array $input): array
    {
        $this->authorization->requireRole($actor, ['student']);
        $this->allowFields($input, ['section_id']);
        if (!array_key_exists('section_id', $input)) {
            throw new HttpException(422, 'Validation failed.', ['section_id' => 'This field is required.']);
        }
        if (!is_int($input['section_id']) || $input['section_id'] < 1) {
            throw new HttpException(422, 'Validation failed.', ['section_id' => 'Use a positive whole number.']);
        }
        $student = $this->profile($actor);
        // Team 3's decision (owner OQ-1), checked before the lock: the same accepted residual window as A4.
        if (!$this->students->isEnrollmentEligible($student->id)) {
            throw new HttpException(409, 'Validation failed.', ['student_id' => 'Your student record is not eligible to enroll.']);
        }
        return $this->catalog->requestEnrollment($student->id, $input['section_id'], $this->now(), $actor->id)->toArray(false);
    }

    public function mine(User $actor, array $query): array
    {
        $this->authorization->requireRole($actor, ['student']);
        $student = $this->profile($actor);
        [$filters, $page, $perPage] = $this->filters($query, ['status', 'term_id']);
        return $this->page($this->catalog->searchEnrollments($filters + ['student_id' => $student->id], $page, $perPage), $page, $perPage, false);
    }

    public function drop(User $actor, int $id): array
    {
        $this->authorization->requireRole($actor, ['student']);
        $student = $this->profile($actor);
        return $this->catalog->dropEnrollment($id, $student->id, $this->now(), $actor->id)->toArray(false);
    }

    public function schedule(User $actor, array $query): array
    {
        $this->authorization->requireRole($actor, ['student']);
        $student = $this->profile($actor);
        if (array_key_exists('term_id', $query)) {
            $termId = filter_var($query['term_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($termId === false) {
                throw new HttpException(422, 'Validation failed.', ['term_id' => 'Use a positive whole number.']);
            }
            $this->catalog->findTerm($termId) ?? throw new HttpException(404, 'Term not found.');
        } else {
            $termId = null;
            foreach ($this->catalog->terms() as $term) {
                if ($term->isCurrent()) {
                    $termId = $term->id();
                }
            }
            if ($termId === null) {
                return ['term_id' => null, 'items' => []];
            }
        }
        // shortcut: one page of 1000 approved rows; a student cannot realistically hold more in one term.
        $rows = $this->catalog->searchEnrollments(['student_id' => $student->id, 'term_id' => $termId, 'status' => 'approved'], 1, 1000);
        $items = [];
        foreach ($rows['enrollments'] as $enrollment) {
            $section = $this->sections->get($enrollment->sectionId());
            $items[] = ['enrollment_id' => $enrollment->id(), 'section_id' => $section['id'], 'course_code' => $section['course_code'],
                'course_title' => $section['course_title'], 'section_code' => $section['section_code'], 'units' => $section['units'],
                'delivery_mode' => $section['delivery_mode'], 'meetings' => $section['meetings'], 'instructors' => $section['instructors']];
        }
        usort($items, static fn (array $a, array $b): int => strcmp($a['course_code'], $b['course_code'])
            ?: strcmp($a['section_code'], $b['section_code']));
        return ['term_id' => $termId, 'items' => $items];
    }

    public function list(User $actor, array $query): array
    {
        $this->authorization->requireRole($actor, self::STAFF);
        [$filters, $page, $perPage] = $this->filters($query, ['status', 'term_id', 'section_id', 'student_id']);
        return $this->page($this->catalog->searchEnrollments($filters, $page, $perPage), $page, $perPage, true);
    }

    public function decide(User $actor, int $id, array $input): array
    {
        $this->authorization->requireRole($actor, self::STAFF);
        $this->allowFields($input, ['status', 'reason']);
        $enrollment = $this->catalog->findEnrollment($id) ?? throw new HttpException(404, 'Enrollment not found.');
        // M1: an actor never decides their own enrollment (earliest point: the target student is known only now).
        $own = $this->students->findByUserId($actor->id);
        if ($own !== null && $own->id === $enrollment->studentId()) {
            throw new HttpException(403, 'Access denied.');
        }
        $errors = [];
        $status = $input['status'] ?? null;
        if (!in_array($status, ['approved', 'rejected'], true)) {
            $errors['status'] = 'Use "approved" or "rejected".';
        }
        $reason = null;
        if (array_key_exists('reason', $input)) {
            $reason = is_string($input['reason']) ? trim($input['reason']) : null;
            if (!Enrollment::validReason($reason)) {
                $errors['reason'] = 'Use 1 to 255 characters without control characters.';
            }
        } elseif ($status === 'rejected') {
            $errors['reason'] = 'A reason is required when rejecting.';
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Validation failed.', $errors);
        }
        // Re-check Team 3 eligibility on approval for the stored student; the repository re-checks state and seats under the lock.
        if ($status === 'approved' && $enrollment->status() === 'pending' && !$this->students->isEnrollmentEligible($enrollment->studentId())) {
            throw new HttpException(409, 'Validation failed.', ['status' => 'The student is not eligible to enroll.']);
        }
        return $this->catalog->decideEnrollment($id, $status, $reason, $this->now(), $actor->id)->toArray(true);
    }

    private function profile(User $actor): StudentSummary
    {
        return $this->students->findByUserId($actor->id) ?? throw new HttpException(403, 'A student profile is required.');
    }

    /** @param list<string> $allowed @return array{0: array, 1: int, 2: int} */
    private function filters(array $query, array $allowed): array
    {
        $errors = [];
        $filters = [];
        foreach ($allowed as $name) {
            if (!array_key_exists($name, $query)) {
                continue;
            }
            if ($name === 'status') {
                in_array($query[$name], Enrollment::STATUSES, true)
                    ? $filters[$name] = $query[$name] : $errors[$name] = 'Use one of: pending, approved, rejected, dropped.';
                continue;
            }
            $value = filter_var($query[$name], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $value === false ? $errors[$name] = 'Use a positive whole number.' : $filters[$name] = $value;
        }
        $page = filter_var($query['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1_000_000]]);
        $perPage = filter_var($query['per_page'] ?? '20', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $page === false && $errors['page'] = 'Use a whole number from 1 to 1000000.';
        $perPage === false && $errors['per_page'] = 'Use a whole number from 1 to 100.';
        if ($errors !== []) {
            throw new HttpException(422, 'Validation failed.', $errors);
        }
        return [$filters, $page, $perPage];
    }

    private function page(array $result, int $page, int $perPage, bool $staff): array
    {
        return ['enrollments' => array_map(static fn (Enrollment $e): array => $e->toArray($staff), $result['enrollments']),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $result['total']]];
    }

    private function allowFields(array $input, array $allowed): void
    {
        $unknown = array_fill_keys(array_diff(array_keys($input), $allowed), 'Unknown field.');
        if ($unknown !== []) {
            throw new HttpException(422, 'Validation failed.', $unknown);
        }
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', ($this->clock)());
    }
}
