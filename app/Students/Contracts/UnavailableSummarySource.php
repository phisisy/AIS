<?php
declare(strict_types=1);

namespace App\Students\Contracts;

/** Default until the owning team provides a verified source: always "unavailable". */
final class UnavailableSummarySource implements AcademicSummarySource
{
    public function countsFor(int $studentId): ?array
    {
        return null;
    }
}
