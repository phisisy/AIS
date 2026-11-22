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

test('Per-module repository overrides inherit the default, fail closed, and keep auth first (T-60)', function (): void {
    $names = ['APP_REPOSITORY', 'APP_REPOSITORY_AUTH', 'APP_REPOSITORY_STUDENTS', 'APP_REPOSITORY_ENROLLMENT', 'APP_REPOSITORY_FACULTY'];
    $previous = array_combine($names, array_map('getenv', $names));
    $load = static fn (): array => require dirname(__DIR__, 2) . '/config/app.php';
    $reset = static function () use ($names): void { foreach ($names as $name) { putenv($name); } };
    try {
        $reset();
        same(['auth' => 'mock', 'students' => 'mock', 'enrollment' => 'mock', 'faculty' => 'mock'], $load()['repositories']);
        putenv('APP_REPOSITORY=mysql');
        same(['auth' => 'mysql', 'students' => 'mysql', 'enrollment' => 'mysql', 'faculty' => 'mysql'], $load()['repositories']);
        putenv('APP_REPOSITORY_STUDENTS=mock');
        same(['auth' => 'mysql', 'students' => 'mock', 'enrollment' => 'mysql', 'faculty' => 'mysql'], $load()['repositories']);
        $reset();
        putenv('APP_REPOSITORY_AUTH=mysql');
        same(['auth' => 'mysql', 'students' => 'mock', 'enrollment' => 'mock', 'faculty' => 'mock'], $load()['repositories']);
        same('mock', $load()['repository']);
        // A module on MySQL while auth stays on mock, or any unknown override value, fails closed.
        foreach ([['APP_REPOSITORY_STUDENTS', 'mysql'], ['APP_REPOSITORY_ENROLLMENT', 'mysql'], ['APP_REPOSITORY_FACULTY', 'mysql'],
                  ['APP_REPOSITORY_AUTH', ''], ['APP_REPOSITORY_FACULTY', 'MYSQL'], ['APP_REPOSITORY_ENROLLMENT', 'sqlite']] as [$name, $value]) {
            $reset();
            putenv($name . '=' . $value);
            same('Invalid repository configuration.', throws(RuntimeException::class, $load)->getMessage());
        }
        $reset();
        putenv('APP_REPOSITORY=mysql');
        putenv('APP_REPOSITORY_AUTH=mock');
        same('Invalid repository configuration.', throws(RuntimeException::class, $load)->getMessage());
    } finally {
        foreach ($previous as $name => $value) { putenv($value === false ? $name : $name . '=' . $value); }
    }
});

test('T-60 7.1 W1: a MySQL module outside APP_ENV=testing is refused before any connection or runtime write', function (): void {
    $directory = tempDirectory();
    $names = ['APP_ENV', 'APP_REPOSITORY_AUTH', 'APP_RUNTIME_PATH', 'DB_DATABASE', 'DB_USERNAME', 'DB_HOST', 'DB_PORT'];
    $previous = array_combine($names, array_map('getenv', $names));
    try {
        // Even a fully valid test target is refused when the environment is not "testing".
        foreach (['APP_ENV' => 'development', 'APP_REPOSITORY_AUTH' => 'mysql', 'APP_RUNTIME_PATH' => $directory,
            'DB_DATABASE' => 'ais_test', 'DB_USERNAME' => 'ais_test_app', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1'] as $k => $v) {
            putenv($k . '=' . $v);
        }
        $error = throws(RuntimeException::class, static function (): void { require dirname(__DIR__, 2) . '/app/bootstrap.php'; });
        same('MySQL repositories are not available yet.', $error->getMessage());
        same([], array_values(array_diff(scandir($directory), ['.', '..'])));
    } finally {
        foreach ($previous as $k => $v) { putenv($v === false ? $k : $k . '=' . $v); }
        removeTestDirectory($directory);
    }
});

test('T-60 7.1 W2/W3: the test-only gate refuses faculty on MySQL and every non-test target before connecting', function (): void {
    $directory = tempDirectory();
    $names = ['APP_ENV', 'APP_REPOSITORY', 'APP_REPOSITORY_AUTH', 'APP_REPOSITORY_STUDENTS', 'APP_REPOSITORY_ENROLLMENT',
        'APP_REPOSITORY_FACULTY', 'APP_RUNTIME_PATH', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_HOST', 'DB_PORT'];
    $previous = array_combine($names, array_map('getenv', $names));
    // Port 1 is closed: if the gate ever let a case through, the result would be "Database connection failed."
    $base = ['APP_ENV' => 'testing', 'APP_RUNTIME_PATH' => $directory, 'DB_DATABASE' => 'ais_test',
        'DB_USERNAME' => 'ais_test_app', 'DB_PASSWORD' => 'unused', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1'];
    $run = static function (array $env) use ($names, $base): string {
        foreach ($names as $n) { putenv($n); }
        foreach (array_replace($base, $env) as $k => $v) { putenv($k . '=' . $v); }
        return throws(RuntimeException::class, static function (): void { require dirname(__DIR__, 2) . '/app/bootstrap.php'; })->getMessage();
    };
    try {
        $refused = 'MySQL repositories are not available yet.';
        // W2: plain APP_REPOSITORY=mysql puts faculty on MySQL too, which stays refused until T-30.
        same($refused, $run(['APP_REPOSITORY' => 'mysql']));
        same($refused, $run(['APP_REPOSITORY_AUTH' => 'mysql', 'APP_REPOSITORY_FACULTY' => 'mysql']));
        // ...while the same request with faculty kept on mock passes the gate (and only then tries to connect).
        same('Database connection failed.', $run(['APP_REPOSITORY' => 'mysql', 'APP_REPOSITORY_FACULTY' => 'mock']));
        // W3: every non-test target is refused before any connection attempt.
        foreach ([['DB_DATABASE' => 'ais'], ['DB_DATABASE' => 'AIS_TEST'], ['DB_USERNAME' => 'ais_app'], ['DB_USERNAME' => 'ais_tester'],
                  ['DB_USERNAME' => 'root'], ['DB_HOST' => 'db.example.test'], ['DB_HOST' => '10.0.0.5']] as $change) {
            same($refused, $run(['APP_REPOSITORY_AUTH' => 'mysql'] + $change));
        }
        same([], array_values(array_diff(scandir($directory), ['.', '..'])));
    } finally {
        foreach ($previous as $k => $v) { putenv($v === false ? $k : $k . '=' . $v); }
        removeTestDirectory($directory);
    }
});

test('T-60 7.1 W4: a database outage behind the satisfied gate is a generic, unchained failure and a generic HTTP 500', function (): void {
    $directory = tempDirectory();
    $names = ['APP_ENV', 'APP_REPOSITORY_AUTH', 'APP_RUNTIME_PATH', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_HOST', 'DB_PORT'];
    $previous = array_combine($names, array_map('getenv', $names));
    $env = ['APP_ENV' => 'testing', 'APP_REPOSITORY_AUTH' => 'mysql', 'APP_RUNTIME_PATH' => $directory,
        'DB_DATABASE' => 'ais_test', 'DB_USERNAME' => 'ais_test_app', 'DB_PASSWORD' => 'w4-secret-value',
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1'];
    $server = null;
    try {
        foreach ($env as $k => $v) { putenv($k . '=' . $v); }
        $error = throws(RuntimeException::class, static function (): void { require dirname(__DIR__, 2) . '/app/bootstrap.php'; });
        same('Database connection failed.', $error->getMessage());
        same(null, $error->getPrevious());
        same([], array_values(array_diff(scandir($directory), ['.', '..'])));
        $server = new CoreHttpServer($env);
        [$status, , $body] = $server->request('GET', '/');
        same(500, $status);
        same('An internal error occurred.', json_decode($body)->message);
        foreach (['127.0.0.1', 'ais_test', 'ais_test_app', 'w4-secret-value', 'SQLSTATE', 'PDO'] as $leak) {
            check(!str_contains($body, $leak), 'Outage response must not reveal connection details.');
        }
    } finally {
        $server?->close();
        foreach ($previous as $k => $v) { putenv($v === false ? $k : $k . '=' . $v); }
        removeTestDirectory($directory);
    }
});

test('T-60 7.1 W5: the default configuration binds only JSON repositories, opens no connection, and bootstrap never reads migration credentials', function (): void {
    $directory = tempDirectory();
    $names = ['APP_ENV', 'APP_REPOSITORY', 'APP_REPOSITORY_AUTH', 'APP_REPOSITORY_STUDENTS', 'APP_REPOSITORY_ENROLLMENT',
        'APP_REPOSITORY_FACULTY', 'APP_RUNTIME_PATH', 'DB_PORT'];
    $previous = array_combine($names, array_map('getenv', $names));
    try {
        foreach ($names as $n) { putenv($n); }
        putenv('APP_RUNTIME_PATH=' . $directory);
        putenv('DB_PORT=1'); // a connection attempt would fail loudly; none must happen
        $container = require dirname(__DIR__, 2) . '/app/bootstrap.php';
        same(App\Auth\Repositories\MockUserRepository::class, get_class($container['users']));
        same(App\Students\Repositories\JsonProgramCatalog::class, get_class($container['programs']));
        same(App\Students\Repositories\MockStudentRepository::class, get_class($container['students']));
        same(App\Enrollment\Repositories\MockCatalogRepository::class, get_class($container['enrollment_catalog']));
        same(App\Enrollment\Contracts\UnavailableFacultyIdentity::class, get_class($container['faculty_identity']));
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/bootstrap.php');
        foreach (['DB_MIGRATION_', 'ATTR_PERSISTENT', 'catch'] as $forbidden) {
            check(!str_contains($source, $forbidden), 'Bootstrap must not contain ' . $forbidden . '.');
        }
        same(1, substr_count($source, 'Database::connect('));
    } finally {
        foreach ($previous as $k => $v) { putenv($v === false ? $k : $k . '=' . $v); }
        removeTestDirectory($directory);
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
