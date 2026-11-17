<?php
declare(strict_types=1);

use App\Core\Environment;

$repository = Environment::get('APP_REPOSITORY', 'mock');
if (!in_array($repository, ['mock', 'mysql'], true)) {
    throw new RuntimeException('Invalid repository configuration.');
}

// Production stays blocked until a deployment (TLS, persistent sessions) is approved.
$environment = Environment::get('APP_ENV', 'development');
if (!in_array($environment, ['development', 'testing'], true)) {
    throw new RuntimeException('Invalid application environment.');
}

return [
    'repository' => $repository,
    'environment' => $environment,
    'https' => filter_var(Environment::get('APP_HTTPS', 'false'), FILTER_VALIDATE_BOOLEAN),
    'runtime_path' => Environment::get('APP_RUNTIME_PATH', dirname(__DIR__) . '/storage/runtime'),
];
