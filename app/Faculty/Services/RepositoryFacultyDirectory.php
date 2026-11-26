<?php
declare(strict_types=1);

namespace App\Faculty\Services;

use App\Faculty\Contracts\FacultyDirectory;
use App\Faculty\Contracts\FacultySummary;
use App\Faculty\Repositories\FacultyRepositoryInterface;

final class RepositoryFacultyDirectory implements FacultyDirectory
{
    public function __construct(private readonly FacultyRepositoryInterface $faculty)
    {
    }

    public function findByUserId(int $userId): ?FacultySummary
    {
        $f = $userId < 1 ? null : $this->faculty->findByUserId($userId);
        return $f === null ? null : new FacultySummary($f->id(), $f->userId(), $f->employeeNumber(), $f->displayName(), $f->status());
    }
}
