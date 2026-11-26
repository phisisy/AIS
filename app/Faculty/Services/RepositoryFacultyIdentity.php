<?php
declare(strict_types=1);

namespace App\Faculty\Services;

use App\Auth\Repositories\UserRepositoryInterface;
use App\Enrollment\Contracts\FacultyIdentity;
use App\Enrollment\Contracts\FacultyIdentityLookup;
use App\Faculty\Models\Faculty;
use App\Faculty\Repositories\FacultyRepositoryInterface;

/**
 * Team 5 implementation of FacultyIdentityLookup (T-30). active = profile active AND account active AND account
 * holds `faculty`, read live on every call (no state kept). Storage errors propagate: callers treat a Throwable as refused.
 */
final class RepositoryFacultyIdentity implements FacultyIdentityLookup
{
    public function __construct(
        private readonly FacultyRepositoryInterface $faculty,
        private readonly UserRepositoryInterface $users,
    ) {
    }

    public function find(int $facultyId): ?FacultyIdentity
    {
        $f = $facultyId < 1 ? null : $this->faculty->findById($facultyId);
        return $f === null ? null : $this->identity($f);
    }

    public function findMany(array $facultyIds): array
    {
        $ids = [];
        foreach ($facultyIds as $id) {
            if (is_int($id) && $id >= 1) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $result = [];
        // shortcut: one users read per distinct account; switch to a Team 2 batch read (or a JOIN in T-40) if section lists get slow
        foreach ($this->faculty->findByIds(array_values($ids)) as $id => $f) {
            $result[$id] = $this->identity($f);
        }
        return $result;
    }

    private function identity(Faculty $f): FacultyIdentity
    {
        $active = false;
        if ($f->status() === 'active') {
            $account = $this->users->findById($f->userId());
            $active = $account !== null && $account->status === 'active' && in_array('faculty', $account->roles, true);
        }
        return new FacultyIdentity($f->id(), $f->displayName(), $active);
    }
}
