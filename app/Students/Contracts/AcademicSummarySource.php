<?php
declare(strict_types=1);

namespace App\Students\Contracts;

/**
 * Counts-only summary owned by another team (owner D3): Team 4 for enrollment, Team 5 for
 * grades, wired in T-11b. Return null when the data is unavailable. Never invent counts:
 * an implementation may return only verified integer counts from its own module.
 */
interface AcademicSummarySource
{
    /** @return array<string, int>|null */
    public function countsFor(int $studentId): ?array;
}
