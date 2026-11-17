<?php
declare(strict_types=1);

namespace App\Students\Services;

use App\Auth\Repositories\UserRepositoryInterface;
use App\Students\Contracts\StudentDirectory;
use App\Students\Contracts\StudentSummary;
use App\Students\Models\Student;
use App\Students\Repositories\StudentRepositoryInterface;

/** StudentDirectory over the Team 3 repository and the Team 2 account lookup (read-only). */
final class RepositoryStudentDirectory implements StudentDirectory
{
    public function __construct(
        private readonly StudentRepositoryInterface $students,
        private readonly UserRepositoryInterface $users,
    ) {
    }

    public function findById(int $studentId): ?StudentSummary
    {
        $student = $this->students->findById($studentId);
        return $student === null ? null : self::summary($student);
    }

    public function findByUserId(int $userId): ?StudentSummary
    {
        $student = $this->students->findByUserId($userId);
        return $student === null ? null : self::summary($student);
    }

    public function isEnrollmentEligible(int $studentId): bool
    {
        $student = $this->students->findById($studentId);
        if ($student === null || $student->toArray()['academic_status'] !== 'active') {
            return false;
        }
        $account = $this->users->findById($student->userId());
        return $account !== null && $account->status === 'active' && in_array('student', $account->roles, true);
    }

    private static function summary(Student $student): StudentSummary
    {
        $row = $student->toArray();
        return new StudentSummary($row['id'], $row['user_id'], $row['student_number'],
            $row['last_name'] . ', ' . trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '')),
            $row['program_code'], $row['year_level'], $row['academic_status']);
    }
}
