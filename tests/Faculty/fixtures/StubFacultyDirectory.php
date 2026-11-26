<?php
declare(strict_types=1);

use App\Faculty\Contracts\FacultyDirectory;
use App\Faculty\Contracts\FacultySummary;

/** Test-only Team 5 double: userId => profile. Default: user 3 is active faculty 10 (assigned to section 1 by the fixture). */
final class StubFacultyDirectory implements FacultyDirectory
{
    /** @var array<int, FacultySummary> */
    public array $byUser;

    public function __construct(?array $byUser = null)
    {
        $this->byUser = $byUser ?? [3 => new FacultySummary(10, 3, 'EMP-010', 'Reyes, Ana', 'active')];
    }

    public function findByUserId(int $userId): ?FacultySummary
    {
        return $this->byUser[$userId] ?? null;
    }
}
