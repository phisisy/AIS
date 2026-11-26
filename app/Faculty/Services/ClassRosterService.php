<?php
declare(strict_types=1);

namespace App\Faculty\Services;

use App\Auth\Models\User;
use App\Auth\Services\AuthorizationService;
use App\Core\HttpException;
use App\Enrollment\Contracts\SectionDirectory;
use App\Faculty\Contracts\FacultyDirectory;
use App\Students\Contracts\StudentDirectory;

/**
 * Team 5 class rosters (T-31). The faculty id comes from the session; access is SectionDirectory::isAssigned (B-04).
 * Unknown and unassigned sections both answer 403, so a section the caller does not teach is never revealed.
 * Order: summary full name ("Last, First Middle"), then student number, case-insensitive.
 */
final class ClassRosterService
{
    public function __construct(
        private readonly FacultyDirectory $faculty,
        private readonly SectionDirectory $sections,
        private readonly StudentDirectory $students,
        private readonly AuthorizationService $authorization,
    ) {
    }

    public function roster(User $actor, int $sectionId, array $query): array
    {
        $this->authorization->requireRole($actor, ['faculty']);
        $errors = [];
        $search = $query['search'] ?? '';
        if (!is_string($search) || mb_strlen($search) > 100 || !mb_check_encoding($search, 'UTF-8')) {
            $errors['search'] = 'Use at most 100 characters.';
        }
        $page = filter_var($query['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1_000_000]]);
        $perPage = filter_var($query['per_page'] ?? '20', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($page === false) {
            $errors['page'] = 'Use a whole number from 1 to 1000000.';
        }
        if ($perPage === false) {
            $errors['per_page'] = 'Use a whole number from 1 to 100.';
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Validation failed.', $errors);
        }
        $profile = $this->faculty->findByUserId($actor->id) ?? throw new HttpException(404, 'Faculty profile not found.');
        if ($profile->status !== 'active' || !$this->sections->isAssigned($profile->id, $sectionId)) {
            throw new HttpException(403, 'Access denied.');
        }
        $needle = mb_strtolower(trim($search));
        $rows = [];
        // shortcut: one summary read per approved student, filtered in memory; add a batch read for very large sections
        foreach ($this->sections->approvedStudentIds($sectionId) as $studentId) {
            $s = $this->students->findById($studentId);
            // Summary unavailable (StudentDirectory returns null): omitted.
            if ($s === null || ($needle !== '' && !str_contains(mb_strtolower($s->fullName), $needle)
                && !str_contains(mb_strtolower($s->studentNumber), $needle))) {
                continue;
            }
            $rows[] = ['student_id' => $s->id, 'student_number' => $s->studentNumber, 'full_name' => $s->fullName,
                'program_code' => $s->programCode, 'year_level' => $s->yearLevel];
        }
        usort($rows, static fn (array $a, array $b): int => [mb_strtolower($a['full_name']), $a['student_number']]
            <=> [mb_strtolower($b['full_name']), $b['student_number']]);
        return ['students' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => count($rows)]];
    }
}
