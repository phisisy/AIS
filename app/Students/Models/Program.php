<?php
declare(strict_types=1);

namespace App\Students\Models;

use RuntimeException;

/** Temporary Team 3 catalog entry; Team 6 owns the final schema. */
final class Program
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly int $maxYearLevel,
    ) {
        if (!preg_match('/\A[A-Z0-9][A-Z0-9-]{0,19}\z/', $code) || trim($name) === '' || strlen($name) > 200
            || $maxYearLevel < 1 || $maxYearLevel > 8) {
            throw new RuntimeException('Invalid stored program.');
        }
    }

    public static function fromArray(mixed $row): self
    {
        if (!is_array($row) || !is_string($row['code'] ?? null) || !is_string($row['name'] ?? null) || !is_int($row['max_year_level'] ?? null)) {
            throw new RuntimeException('Invalid stored program.');
        }
        return new self($row['code'], $row['name'], $row['max_year_level']);
    }

    public function toArray(): array
    {
        return ['code' => $this->code, 'name' => $this->name, 'max_year_level' => $this->maxYearLevel];
    }
}
