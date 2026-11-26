<?php
declare(strict_types=1);

namespace App\Enrollment\Services;

use App\Enrollment\Models\Enrollment;
use App\Enrollment\Repositories\CatalogRepositoryInterface;
use App\Students\Contracts\AcademicSummarySource;

/** Team 4's verified counts for the T-11a academic record: one count per enrollment status. Errors propagate (record shows "unavailable"). */
final class EnrollmentSummarySource implements AcademicSummarySource
{
    public function __construct(private readonly CatalogRepositoryInterface $catalog)
    {
    }

    public function countsFor(int $studentId): ?array
    {
        $counts = [];
        foreach (Enrollment::STATUSES as $status) {
            $counts[$status] = $this->catalog->searchEnrollments(['student_id' => $studentId, 'status' => $status], 1, 1)['total'];
        }
        return $counts;
    }
}
