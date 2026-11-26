<?php
declare(strict_types=1);

namespace App\Faculty\Services;

/**
 * The only grade scale definition (B-05). Bootstrap binds official() by default, or demo() only when APP_GRADE_SCALE=demo;
 * tests construct their own under tests/. An empty scale is "unconfirmed" and GradeService then fails closed.
 */
final class GradeScale
{
    /** @param list<string> $values */
    public function __construct(private readonly array $values)
    {
    }

    public function allows(string $value): bool
    {
        return in_array($value, $this->values, true);
    }

    public function isConfirmed(): bool
    {
        return $this->values !== [];
    }

    /**
     * Unconfirmed until the owner provides the official scale (B-05); no official values exist yet. Future activation
     * (docs/plans/GRADE_SCALE_ACTIVATION.md): fill `[]` below with the owner's list and, in the same change set, add a new
     * forward-only migration V0011 replacing CHECK `ck_grades_value` (V0010 holds the demo scale and is never edited;
     * removing the old constraint needs explicit owner approval), plus the history CHECK, config and test decisions in that plan.
     *
     * Invariants the code relies on:
     *  - each code is a string compared exactly (allows() uses strict in_array: case, spaces and decimals matter);
     *  - each code is 1 to 8 characters (Grade validation and CHECK ck_grades_value_len);
     *  - codes are unique;
     *  - the list is non-empty once confirmed (empty means unconfirmed: entry and correction fail closed with 409 grade_scale).
     * The model has no notion of passing or terminal codes; none are defined here.
     */
    public static function official(): self
    {
        return new self([]);
    }

    /**
     * Owner-approved for the academic demonstration only (2026-11-26); not the official university grading policy.
     * Selected only by APP_GRADE_SCALE=demo. Meanings (documentation only, not modelled): 1.00-3.00 passing, 5.00 failing,
     * INC incomplete, DRP dropped.
     */
    public static function demo(): self
    {
        return new self(['1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00', '5.00', 'INC', 'DRP']);
    }
}
