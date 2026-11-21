<?php
declare(strict_types=1);

use App\Core\JsonStore;
use App\Enrollment\Repositories\MockCatalogRepository;

require_once __DIR__ . '/CatalogRepositoryContract.php';

catalogRepositoryContract('mock', static function (): array {
    $directory = tempDirectory();
    return [new MockCatalogRepository(new JsonStore($directory . '/enrollment.json')),
        static fn () => removeTestDirectory($directory)];
});
