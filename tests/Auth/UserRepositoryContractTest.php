<?php
declare(strict_types=1);

use App\Auth\Repositories\MockUserRepository;
use App\Core\JsonStore;

require_once __DIR__ . '/UserRepositoryContract.php';

userRepositoryContract('mock', static function (): array {
    $directory = tempDirectory();
    $repository = new MockUserRepository(new JsonStore($directory . '/users.json'));
    $repository->seed(userContractRows());
    return [$repository, static fn () => removeTestDirectory($directory)];
});
