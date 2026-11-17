<?php
declare(strict_types=1);

namespace App\Students\Contracts;

use App\Students\Models\Program;

/** Temporary Team 3 program catalog (development/testing). Team 6 owns the final schema. */
interface ProgramCatalogInterface
{
    /** $code is matched exactly (callers pass uppercase). */
    public function find(string $code): ?Program;

    /** @return list<Program> */
    public function all(): array;
}
