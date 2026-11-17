<?php
declare(strict_types=1);

namespace App\Students\Contracts;

/**
 * Team 3 → Teams 4/5, in-process only (no HTTP route exposes it). Callers are trusted
 * module services that have already authenticated and authorized their own requests;
 * the directory returns only summary fields.
 */
interface StudentDirectory
{
    public function findById(int $studentId): ?StudentSummary;

    public function findByUserId(int $userId): ?StudentSummary;

    /**
     * Team 3's part of eligibility (owner D2): academic status is `active`, and the linked
     * account is active and holds the `student` role. Team 4 enforces every other enrollment
     * requirement and remains responsible for the final decision. Unknown student → false.
     */
    public function isEnrollmentEligible(int $studentId): bool;
}
