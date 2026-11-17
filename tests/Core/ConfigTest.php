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
