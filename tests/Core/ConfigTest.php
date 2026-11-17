<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Environment;

test('Environment loads literal values and process overrides', function (): void {
    $directory = tempDirectory();
    $previous = getenv('AIS_TEST_VALUE');
    try {
        putenv('AIS_TEST_VALUE');
        file_put_contents($directory . '/.env', "# example\nAIS_TEST_VALUE='literal \$HOME'\nAIS_TEST_EMPTY=\nAIS_TEST_QUOTED=\"a b\"\n");
        Environment::load($directory . '/.env');
        same('literal $HOME', Environment::get('AIS_TEST_VALUE'));
        same('', Environment::get('AIS_TEST_EMPTY'));
        same('a b', Environment::get('AIS_TEST_QUOTED'));
        same('fallback', Environment::get('AIS_TEST_MISSING', 'fallback'));
        putenv('AIS_TEST_VALUE=process');
        same('process', Environment::get('AIS_TEST_VALUE'));
        putenv('AIS_TEST_VALUE=');
        same('', Environment::get('AIS_TEST_VALUE'));
        Environment::load($directory . '/missing.env');
        same(null, Environment::get('AIS_TEST_QUOTED'));
    } finally {
        putenv($previous === false ? 'AIS_TEST_VALUE' : 'AIS_TEST_VALUE=' . $previous);
        Environment::load($directory . '/missing.env');
        removeTestDirectory($directory);
    }
});

test('Environment rejects malformed and duplicate settings without exposing values', function (): void {
    $directory = tempDirectory();
    try {
        foreach (["BAD KEY=secret", "KEY='secret", "KEY=one\nKEY=secret"] as $contents) {
            file_put_contents($directory . '/.env', $contents);
            $error = throws(RuntimeException::class, fn () => Environment::load($directory . '/.env'));
            same('Invalid environment configuration.', $error->getMessage());
        }
    } finally {
        Environment::load($directory . '/missing.env');
        removeTestDirectory($directory);
    }
});

test('Repository selection defaults to mock and fails closed for unknown values', function (): void {
    $previous = getenv('APP_REPOSITORY');
    try {
        putenv('APP_REPOSITORY');
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        same('mock', $config['repository']);
        foreach (['mock', 'mysql'] as $mode) {
            putenv('APP_REPOSITORY=' . $mode);
            $config = require dirname(__DIR__, 2) . '/config/app.php';
            same($mode, $config['repository']);
        }
        foreach (['', 'MYSQL', 'unknown'] as $mode) {
            putenv('APP_REPOSITORY=' . $mode);
            $error = throws(RuntimeException::class, function (): void {
                require dirname(__DIR__, 2) . '/config/app.php';
            });
            same('Invalid repository configuration.', $error->getMessage());
        }
    } finally {
        putenv($previous === false ? 'APP_REPOSITORY' : 'APP_REPOSITORY=' . $previous);
    }
});

test('PDO factory rejects unsafe DSN settings without connecting or leaking secrets', function (): void {
    $base = ['host' => '127.0.0.1', 'port' => '3306', 'database' => 'ais_test',
        'username' => 'test', 'password' => 'do-not-expose'];
    foreach ([['host' => 'localhost;dbname=other'], ['database' => 'ais;host=other'],
        ['port' => '0'], ['port' => '65536'], ['port' => '3306;other'], ['username' => '']] as $override) {
        $error = throws(RuntimeException::class, fn () => Database::connect(array_replace($base, $override)));
        same('Invalid database configuration.', $error->getMessage());
        same(null, $error->getPrevious());
    }
});

test('Application environment allows development and testing only, defaulting to development', function (): void {
    $previous = getenv('APP_ENV');
    try {
        putenv('APP_ENV');
        same('development', (require dirname(__DIR__, 2) . '/config/app.php')['environment']);
        foreach (['development', 'testing'] as $environment) {
            putenv('APP_ENV=' . $environment);
            same($environment, (require dirname(__DIR__, 2) . '/config/app.php')['environment']);
        }
        foreach (['production', 'prod', 'Development', '', 'test'] as $environment) {
            putenv('APP_ENV=' . $environment);
            $error = throws(RuntimeException::class, function (): void {
                require dirname(__DIR__, 2) . '/config/app.php';
            });
            same('Invalid application environment.', $error->getMessage());
        }
    } finally {
        putenv($previous === false ? 'APP_ENV' : 'APP_ENV=' . $previous);
    }
});

test('Runtime path defaults outside the project on Windows and stays configurable', function (): void {
    $previous = getenv('APP_RUNTIME_PATH');
    try {
        putenv('APP_RUNTIME_PATH');
        $default = (require dirname(__DIR__, 2) . '/config/app.php')['runtime_path'];
        if (PHP_OS_FAMILY === 'Windows') {
            same('C:/ais-runtime', $default);
        }
        $project = strtolower(str_replace('\\', '/', dirname(__DIR__, 2)));
        check(PHP_OS_FAMILY !== 'Windows' || !str_starts_with(strtolower($default), $project), 'Default must be outside the project.');
        putenv('APP_RUNTIME_PATH=D:/custom-runtime');
        same('D:/custom-runtime', (require dirname(__DIR__, 2) . '/config/app.php')['runtime_path']);
    } finally {
        putenv($previous === false ? 'APP_RUNTIME_PATH' : 'APP_RUNTIME_PATH=' . $previous);
    }
});

test('RuntimeDirectory creates a private directory and refuses unsafe or unusable paths', function (): void {
    $directory = tempDirectory();
    try {
        $public = $directory . DIRECTORY_SEPARATOR . 'public';
        mkdir($public);
        $created = App\Core\RuntimeDirectory::prepare($directory . '/runtime/nested', $public);
        same(realpath($directory . '/runtime/nested'), $created);
        check(is_dir($created));
        same($created, App\Core\RuntimeDirectory::prepare($created, $public));
        file_put_contents($directory . '/a-file', 'x');
        $cases = [
            'relative' => ['storage/runtime', 'Invalid runtime directory.'],
            'empty' => ['', 'Invalid runtime directory.'],
            'stream' => ['file://' . $directory, 'Invalid runtime directory.'],
            'nul byte' => [$directory . "\0x", 'Invalid runtime directory.'],
            'is a file' => [$directory . '/a-file', 'Runtime directory unavailable.'],
            'under a file' => [$directory . '/a-file/sub', 'Runtime directory unavailable.'],
            'public itself' => [$public, 'Runtime directory must not be web-public.'],
            'inside public' => [$public . '/sessions', 'Runtime directory must not be web-public.'],
            'dot-dot into public' => [$directory . '/runtime/../public/x', 'Runtime directory must not be web-public.'],
        ];
        foreach ($cases as $name => [$path, $message]) {
            $error = throws(RuntimeException::class, fn () => App\Core\RuntimeDirectory::prepare($path, $public));
            check($error->getMessage() === $message, "{$name}: got '{$error->getMessage()}'");
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $error = throws(RuntimeException::class, fn () => App\Core\RuntimeDirectory::prepare(strtoupper($public) . '\\x', $public));
            same('Runtime directory must not be web-public.', $error->getMessage());
        }
    } finally {
        removeTestDirectory($directory);
    }
});

test('HTTP returns a generic 500 when the runtime directory is unavailable', function (): void {
    $directory = tempDirectory();
    $server = null;
    try {
        file_put_contents($directory . '/not-a-directory', 'x');
        $server = new CoreHttpServer(['APP_RUNTIME_PATH' => $directory . '/not-a-directory']);
        [$status, , $body] = $server->request('GET', '/');
        same(500, $status);
        same('An internal error occurred.', json_decode($body)->message);
        check(!str_contains($body, 'not-a-directory') && !str_contains($body, 'untime'), 'Error must not reveal the path.');
    } finally {
        $server?->close();
        removeTestDirectory($directory);
    }
});
