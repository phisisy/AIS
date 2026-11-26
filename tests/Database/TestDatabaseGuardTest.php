<?php
declare(strict_types=1);

require_once __DIR__ . '/MySQLTestSupport.php';

test('MySQL guard rejects unsafe environment before connecting', function (): void {
    $safe = [
        'AIS_MYSQL_TESTS' => '1', 'APP_ENV' => 'testing', 'DB_DATABASE' => 'ais_test',
        'AIS_MYSQL_TEST_TARGET' => 'ais_test', 'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306', 'DB_USERNAME' => 'ais_tester', 'DB_PASSWORD' => 'test-only',
    ];
    $original = [];
    foreach ($safe as $key => $value) {
        $original[$key] = getenv($key);
        putenv($key . '=' . $value);
    }
    try {
        MySQLTestSupport::assertEnvironment();
        foreach ([
            'AIS_MYSQL_TESTS' => '0', 'APP_ENV' => 'production', 'DB_DATABASE' => 'ais',
            'AIS_MYSQL_TEST_TARGET' => 'ais', 'DB_HOST' => 'db.internal', 'DB_PORT' => '0',
            'DB_USERNAME' => 'root',
        ] as $key => $unsafe) {
            putenv($key . '=' . $unsafe);
            throws(RuntimeException::class, static fn () => MySQLTestSupport::assertEnvironment());
            putenv($key . '=' . $safe[$key]);
        }
        putenv('DB_DATABASE=ais_test_copy');
        throws(RuntimeException::class, static fn () => MySQLTestSupport::assertEnvironment());
    } finally {
        foreach ($original as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }
});
