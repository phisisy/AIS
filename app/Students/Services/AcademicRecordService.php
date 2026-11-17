<?php
declare(strict_types=1);

namespace App\Students\Services;

use App\Auth\Models\User;
use App\Auth\Services\AuthorizationService;
use App\Core\HttpException;
use App\Students\Contracts\AcademicSummarySource;
use App\Students\Contracts\ProgramCatalogInterface;
use App\Students\Models\Student;
use App\Students\Repositories\StudentRepositoryInterface;

/**
 * T-11a academic record view: Team 3 profile data, paginated academic history, and
 * counts-only summaries from other teams' verified sources (unavailable until T-11b).
 * Access: registrar/admin any record; the owning account while it holds `student`.
 * Everything else is 404, identical to a missing record. Faculty have no access here.
 */
final class AcademicRecordService
{
    private const STAFF = ['registrar', 'admin'];
    private const NOT_FOUND = 'Student not found.';

    public function __construct(
        private readonly StudentRepositoryInterface $students,
        private readonly ProgramCatalogInterface $programs,
        private readonly AuthorizationService $authorization,
        private readonly AcademicSummarySource $enrollmentSummary,
        private readonly AcademicSummarySource $gradeSummary,
    ) {
    }

    public function forStudent(User $actor, int $id, array $query): array
    {
        $this->authorization->requireRole($actor, [...self::STAFF, 'student']);
        [$page, $perPage] = $this->pagination($query);
        $student = $this->students->findById($id);
        $allowed = $student !== null && ($this->authorization->hasAnyRole($actor, self::STAFF)
            || ($student->userId() === $actor->id && $this->authorization->hasAnyRole($actor, ['student'])));
        if (!$allowed) {
            throw new HttpException(404, self::NOT_FOUND);
        }
        return $this->record($student, $page, $perPage, $this->authorization->hasAnyRole($actor, self::STAFF));
    }

    public function forMe(User $actor, array $query): array
    {
        $this->authorization->requireRole($actor, ['student']);
        [$page, $perPage] = $this->pagination($query);
        $student = $this->students->findByUserId($actor->id) ?? throw new HttpException(404, self::NOT_FOUND);
        return $this->record($student, $page, $perPage, $this->authorization->hasAnyRole($actor, self::STAFF));
    }

    /** Read-only program catalog for any signed-in user. */
    public function programs(User $actor): array
    {
        $programs = $this->programs->all();
        usort($programs, static fn ($a, $b): int => strcmp($a->code, $b->code));
        return ['programs' => array_map(static fn ($p): array => $p->toArray(), $programs)];
    }

    /** $staffView: only registrar/admin see which account made each change (actor_id). */
    private function record(Student $student, int $page, int $perPage, bool $staffView): array
    {
        $row = $student->toArray();
        $history = $this->students->history($student->id(), $page, $perPage);
        if (!$staffView) {
            $history['entries'] = array_map(static function (array $entry): array {
                unset($entry['actor_id']);
                return $entry;
            }, $history['entries']);
        }
        return [
            'student' => ['id' => $row['id'], 'student_number' => $row['student_number'],
                'full_name' => $row['last_name'] . ', ' . trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '')),
                'program_code' => $row['program_code'], 'program_name' => $this->programs->find($row['program_code'])?->name,
                'year_level' => $row['year_level'], 'academic_status' => $row['academic_status'], 'admission_year' => $row['admission_year']],
            'history' => ['entries' => $history['entries'],
                'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $history['total']]],
            'enrollment_summary' => self::summary($this->enrollmentSummary, $student->id()),
            'grades_summary' => self::summary($this->gradeSummary, $student->id()),
        ];
    }

    /** Explicit unavailable state on null, error or anything other than string => non-negative int. */
    private static function summary(AcademicSummarySource $source, int $studentId): array
    {
        try {
            $counts = $source->countsFor($studentId);
        } catch (\Throwable) {
            return ['available' => false];
        }
        if (!is_array($counts) || $counts === []) {
            return ['available' => false];
        }
        foreach ($counts as $key => $value) {
            if (!is_string($key) || !preg_match('/\A[a-z][a-z_]{0,39}\z/', $key) || !is_int($value) || $value < 0) {
                return ['available' => false];
            }
        }
        return ['available' => true, 'counts' => $counts];
    }

    private function pagination(array $query): array
    {
        $page = filter_var($query['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1_000_000]]);
        $perPage = filter_var($query['per_page'] ?? '50', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $errors = [];
        if ($page === false) {
            $errors['page'] = 'Use a whole number from 1 to 1000000.';
        }
        if ($perPage === false) {
            $errors['per_page'] = 'Use a whole number from 1 to 100.';
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Validation failed.', $errors);
        }
        return [$page, $perPage];
    }
}
