<?php
declare(strict_types=1);

namespace App\Students\Contracts;

/** Read-only student summary for in-process use by Teams 4 and 5. Never returned to clients as-is. */
final class StudentSummary
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $studentNumber,
        public readonly string $fullName,
        public readonly string $programCode,
        public readonly int $yearLevel,
        public readonly string $academicStatus,
    ) {
    }
}
