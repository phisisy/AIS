<?php
declare(strict_types=1);

namespace App\Auth\Repositories;

use App\Auth\Models\User;

interface UserRepositoryInterface
{
    public function findById(int $id): ?User;

    public function findByEmail(string $email): ?User;
}
