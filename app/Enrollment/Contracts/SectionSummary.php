<?php
declare(strict_types=1);

namespace App\Enrollment\Contracts;

/** Read-only section fact for Teams 3 and 5, in-process only. Never returned to clients as-is. */
final class SectionSummary
{
    public function __construct(
        public readonly int $id,
        public readonly int $termId,
        public readonly string $courseCode,
        public readonly string $sectionCode,
    ) {
    }
}
