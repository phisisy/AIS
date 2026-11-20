<?php
declare(strict_types=1);

use App\Enrollment\Contracts\FacultyIdentity;
use App\Enrollment\Contracts\FacultyIdentityLookup;

final class StubFacultyIdentity implements FacultyIdentityLookup
{
    public int $findCalls = 0;
    public int $findManyCalls = 0;
    public bool $throw = false;
    public bool $throwError = false;
    public bool $mismatch = false;

    /** @param array<int, array{name:string,active:bool}> $rows */
    public function __construct(public array $rows = [])
    {
    }

    public function find(int $facultyId): ?FacultyIdentity
    {
        $this->findCalls++;
        if ($this->throwError) {
            throw new Error('Lookup unavailable.');
        }
        if ($this->throw) {
            throw new RuntimeException('Lookup unavailable.');
        }
        $row = $this->rows[$facultyId] ?? null;
        return $row === null ? null : new FacultyIdentity($this->mismatch ? $facultyId + 1 : $facultyId, $row['name'], $row['active']);
    }

    public function findMany(array $facultyIds): array
    {
        $this->findManyCalls++;
        if ($this->throw || $this->throwError) {
            throw new RuntimeException('Lookup unavailable.');
        }
        $found = [];
        foreach ($facultyIds as $id) {
            $identity = $this->find($id);
            if ($identity !== null) {
                $found[$id] = $identity;
            }
        }
        return $found;
    }
}
