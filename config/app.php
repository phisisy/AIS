<?php
declare(strict_types=1);

use App\Core\Environment;

$repository = Environment::get('APP_REPOSITORY', 'mock');
if (!in_array($repository, ['mock', 'mysql'], true)) {
    throw new RuntimeException('Invalid repository configuration.');
}
// T-60: optional per-module overrides (APP_REPOSITORY_<MODULE>); unknown values fail closed.
$repositories = [];
foreach (['auth', 'students', 'enrollment', 'faculty', 'grades'] as $module) {
    $repositories[$module] = Environment::get('APP_REPOSITORY_' . strtoupper($module), $repository);
    if (!in_array($repositories[$module], ['mock', 'mysql'], true)) {
        throw new RuntimeException('Invalid repository configuration.');
    }
}
// Every module resolves accounts through Team 2, so no module may be on MySQL while auth is not.
if ($repositories['auth'] === 'mock' && in_array('mysql', $repositories, true)) {
    throw new RuntimeException('Invalid repository configuration.');
}
// T-22: enrollments reference students (FK), so enrollment on MySQL needs students on MySQL; never a JSON fallback.
if ($repositories['enrollment'] === 'mysql' && $repositories['students'] !== 'mysql') {
    throw new RuntimeException('Invalid repository configuration.');
}
// T-30: the real faculty lookup FK-checks assignment faculty ids, so enrollment on MySQL needs faculty on MySQL; never a JSON fallback.
if ($repositories['enrollment'] === 'mysql' && $repositories['faculty'] !== 'mysql') {
    throw new RuntimeException('Invalid repository configuration.');
}
// T-32: grades reference students (FK) and share the enrollment lock for finalize, so grades on MySQL needs both on MySQL; never a JSON fallback.
if ($repositories['grades'] === 'mysql' && ($repositories['students'] !== 'mysql' || $repositories['enrollment'] !== 'mysql')) {
    throw new RuntimeException('Invalid repository configuration.');
}

// Production stays blocked until a deployment (TLS, persistent sessions) is approved.
$environment = Environment::get('APP_ENV', 'development');
if (!in_array($environment, ['development', 'testing'], true)) {
    throw new RuntimeException('Invalid application environment.');
}

// Demo-only grade scale switch (owner decision 2026-11-26): off (default, fail closed) or demo; anything else is refused.
$gradeScale = Environment::get('APP_GRADE_SCALE', 'off');
if (!in_array($gradeScale, ['off', 'demo'], true)) {
    throw new RuntimeException('Invalid grade scale configuration.');
}

return [
    'grade_scale' => $gradeScale,
    'repository' => $repository,
    'repositories' => $repositories,
    'environment' => $environment,
    'https' => filter_var(Environment::get('APP_HTTPS', 'false'), FILTER_VALIDATE_BOOLEAN),
    // Default outside the (OneDrive-synced) project on Windows so sessions and hashes stay local.
    'runtime_path' => Environment::get(
        'APP_RUNTIME_PATH',
        PHP_OS_FAMILY === 'Windows' ? 'C:/ais-runtime' : dirname(__DIR__) . '/storage/runtime'
    ),
];
