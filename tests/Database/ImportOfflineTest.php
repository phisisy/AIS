<?php
declare(strict_types=1);

use Database\Tools\ImportRefused;
use Database\Tools\JsonImporter;

require_once dirname(__DIR__, 2) . '/database/tools/JsonImporter.php';

/** Phase 8 CLI tests that must refuse before opening a database connection. */
function phase8CliEnvironment(string $runtime, array $overrides = []): array
{
    $environment = getenv();
    check(is_array($environment));
    return array_replace($environment, [
        'APP_ENV' => 'testing', 'APP_RUNTIME_PATH' => $runtime,
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'ais_test',
        'DB_MIGRATION_USERNAME' => 'ais_tester', 'DB_MIGRATION_PASSWORD' => '',
    ], $overrides);
}

function phase8ImportCommand(string $runtime, string $module = 'auth', string $target = 'ais_test', array $extra = []): array
{
    return array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/database/import_json.php',
        '--module=' . $module, '--runtime=' . $runtime, '--target=' . $target], $extra);
}

function phase8VerifyCommand(string $target = 'ais_test'): array
{
    return [PHP_BINARY, dirname(__DIR__, 2) . '/database/verify.php', '--target=' . $target];
}

function phase8OfflineFiles(string $runtime, string $module): array
{
    $files = ['auth'=>['auth/users.json'], 'students'=>['students/students.json', 'students/programs.json'],
        'enrollment'=>['enrollment/enrollment.json']][$module];
    foreach ($files as $file) {
        $directory = dirname($runtime . '/' . $file);
        if (!is_dir($directory)) { mkdir($directory, 0700, true); }
        file_put_contents($runtime . '/' . $file, '{}');
        file_put_contents($runtime . '/' . $file . '.lock', '');
    }
    return $files;
}

function phase8OfflineListing(string $runtime): array
{
    $listing = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        $listing[substr($entry->getPathname(), strlen($runtime) + 1)] = $entry->isFile()
            ? file_get_contents($entry->getPathname()) : null;
    }
    ksort($listing);
    return $listing;
}

test('Phase 8 offline: importer accepts absolute Windows runtime and refuses relative or URL paths', static function (): void {
    $runtime = tempDirectory();
    try {
        phase8OfflineFiles($runtime, 'auth');
        $resolved = realpath($runtime);
        check($resolved !== false);
        if (DIRECTORY_SEPARATOR === '\\') { check(str_contains($resolved, '\\')); }
        $parse = static fn (string $path): array => JsonImporter::parseArguments([
            'import_json.php', '--module=auth', '--runtime=' . $path, '--target=ais_test',
        ]);
        same($resolved, $parse($resolved)['runtime']);
        same($resolved, $parse(str_replace('\\', '/', $resolved))['runtime']);
        same('Runtime refused.', throws(ImportRefused::class, static fn () => $parse('.'))->getMessage());
        same('Import arguments refused.', throws(ImportRefused::class,
            static fn () => $parse('file://example.test/runtime'))->getMessage());
    } finally {
        removeTestDirectory($runtime);
    }
});

test('Phase 8 offline: missing source files and locks never change runtime', static function (): void {
    foreach (['auth', 'students', 'enrollment'] as $module) {
        $runtime = tempDirectory();
        try {
            foreach (phase8OfflineFiles($runtime, $module) as $file) {
                foreach ([$file, $file . '.lock'] as $missing) {
                    $saved = file_get_contents($runtime . '/' . $missing);
                    unlink($runtime . '/' . $missing);
                    $before = phase8OfflineListing($runtime);
                    [$exit, $stdout, $stderr] = runProcess(phase8ImportCommand($runtime, $module), phase8CliEnvironment($runtime));
                    same(2, $exit); same('', $stdout); same("Runtime refused.\n", $stderr);
                    same($before, phase8OfflineListing($runtime));
                    file_put_contents($runtime . '/' . $missing, $saved);
                }
            }
        } finally { removeTestDirectory($runtime); }
    }
});

test('Phase 8 offline: live runtime comes from the env file, not process environment', static function (): void {
    $live = tempDirectory(); $other = tempDirectory();
    try {
        phase8OfflineFiles($live, 'auth'); phase8OfflineFiles($other, 'auth');
        $fakeEnv = $other . '/fake.env';
        file_put_contents($fakeEnv, 'APP_RUNTIME_PATH=' . $live . "\n");
        $parse = static fn (string $path): array => JsonImporter::parseArguments(
            array_slice(phase8ImportCommand($path), 1), $fakeEnv);
        same('Runtime refused.', throws(ImportRefused::class, static fn () => $parse($live))->getMessage());
        same(realpath($other), $parse($other)['runtime']);
    } finally { removeTestDirectory($live); removeTestDirectory($other); }
});

test('Phase 8 offline: UNC and network paths are refused', static function (): void {
    foreach (['\\\\host\\share', '//host/share', '\\/host/share', '/\\host/share'] as $path) {
        same('Runtime refused.', throws(ImportRefused::class, static fn () =>
            JsonImporter::parseArguments(array_slice(phase8ImportCommand($path), 1)))->getMessage());
    }
});

test('Phase 8 offline: importer refuses a non-test target before connection', static function (): void {
    $runtime = tempDirectory();
    try {
        [$exit, $stdout, $stderr] = runProcess(phase8ImportCommand($runtime, 'auth', 'ais'), phase8CliEnvironment($runtime));
        same(2, $exit);
        same('', $stdout);
        check($stderr !== '');
    } finally {
        removeTestDirectory($runtime);
    }
});

test('Phase 8 offline: importer refuses database and host mismatches', static function (): void {
    $runtime = tempDirectory();
    try {
        foreach ([['DB_DATABASE' => 'ais'], ['DB_DATABASE' => 'ais_test_copy'], ['DB_HOST' => 'db.example.test']] as $change) {
            [$exit, $stdout] = runProcess(phase8ImportCommand($runtime), phase8CliEnvironment($runtime, $change));
            same(2, $exit);
            same('', $stdout);
        }
    } finally {
        removeTestDirectory($runtime);
    }
});

test('Phase 8 offline: faculty import is deferred', static function (): void {
    $runtime = tempDirectory();
    try {
        [$exit, $stdout, $stderr] = runProcess(phase8ImportCommand($runtime, 'faculty'), phase8CliEnvironment($runtime));
        same(2, $exit);
        same('', $stdout);
        check(str_contains(strtolower($stderr), 't-30'));
    } finally {
        removeTestDirectory($runtime);
    }
});

test('Phase 8 offline: importer refuses root and malformed arguments', static function (): void {
    $runtime = tempDirectory();
    try {
        [$exit] = runProcess(phase8ImportCommand($runtime), phase8CliEnvironment($runtime, ['DB_MIGRATION_USERNAME' => 'root']));
        same(2, $exit);
        [$exit] = runProcess(phase8ImportCommand($runtime), phase8CliEnvironment($runtime, ['DB_MIGRATION_USERNAME' => 'ais_test_app']));
        same(2, $exit);
        [$exit] = runProcess([PHP_BINARY, dirname(__DIR__, 2) . '/database/import_json.php', '--module=auth'],
            phase8CliEnvironment($runtime));
        same(2, $exit);
    } finally {
        removeTestDirectory($runtime);
    }
});

test('Phase 8 offline: verifier refuses a non-test target and malformed arguments', static function (): void {
    $runtime = tempDirectory();
    try {
        [$exit, $stdout] = runProcess(phase8VerifyCommand('ais'), phase8CliEnvironment($runtime));
        same(2, $exit);
        foreach ([['DB_HOST'=>'db.example.test'], ['DB_USERNAME'=>'root'], ['DB_USERNAME'=>'unexpected'],
            ['DB_PORT'=>'0'], ['DB_PORT'=>'65536'], ['DB_PORT'=>'bad']] as $change) {
            [$exit, $stdout] = runProcess(phase8VerifyCommand(), phase8CliEnvironment($runtime, $change));
            same(2, $exit); same('', $stdout);
        }
        same('', $stdout);
        [$exit] = runProcess([PHP_BINARY, dirname(__DIR__, 2) . '/database/verify.php'],
            phase8CliEnvironment($runtime));
        same(2, $exit);
    } finally {
        removeTestDirectory($runtime);
    }
});
