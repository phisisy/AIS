<?php
declare(strict_types=1);

use App\Core\JsonStore;
use App\Students\Repositories\JsonProgramCatalog;
use App\Students\Repositories\MockStudentRepository;

require_once __DIR__ . '/StudentRepositoryContract.php';

studentRepositoryContract('mock', static function (): array {
    $directory = tempDirectory();
    $catalog = new JsonProgramCatalog(new JsonStore($directory . '/programs.json'));
    $catalog->seed(studentContractPrograms());
    return [new MockStudentRepository(new JsonStore($directory . '/students.json')), $catalog,
        static fn () => removeTestDirectory($directory)];
});
