<?php
declare(strict_types=1);

use App\Core\Environment;

$repository = Environment::get('APP_REPOSITORY', 'mock');
if (!in_array($repository, ['mock', 'mysql'], true)) {
    throw new RuntimeException('Invalid repository configuration.');
}

return [
    'repository' => $repository,
    'environment' => Environment::get('APP_ENV', 'development'),
    'runtime_path' => Environment::get('APP_RUNTIME_PATH', dirname(__DIR__) . '/storage/runtime'),
];
