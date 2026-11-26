<?php
declare(strict_types=1);

use App\Students\Contracts\StudentDirectory;
use App\Students\Contracts\StudentSummary;

/** Test-only Team 3 double: users 4, 6 and 7 own students 1, 2 and 3. Eligibility is switchable per student. */
final class StubStudentDirectory implements StudentDirectory
{
    /** @param array<int,int> $byUser userId => studentId @param array<int,bool> $eligible studentId => eligible */
    public function __construct(public array $byUser = [4 => 1, 6 => 2, 7 => 3], public array $eligible = [1 => true, 2 => true, 3 => true])
    {
    }

    public function findById(int $studentId): ?StudentSummary
    {
        $userId = array_search($studentId, $this->byUser, true);
        return $userId === false ? null : $this->summary($studentId, $userId);
    }

    public function findByUserId(int $userId): ?StudentSummary
    {
        return isset($this->byUser[$userId]) ? $this->summary($this->byUser[$userId], $userId) : null;
    }

    public function isEnrollmentEligible(int $studentId): bool
    {
        return $this->eligible[$studentId] ?? false;
    }

    private function summary(int $studentId, int $userId): StudentSummary
    {
        return new StudentSummary($studentId, $userId, '2026-0000' . $studentId, 'Student, Test', 'BSCS', 2, 'active');
    }
}
