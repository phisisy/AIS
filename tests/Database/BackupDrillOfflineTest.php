<?php
declare(strict_types=1);

/** DB-33 drill: guard refusals only. Every case breaks at least one guard, so no process or connection can start. UNVERIFIED. */
const DRILL_SECRET = 'S3CRET-drill-marker';

function drillOfflineEnv(array $overrides = []): array
{
    $environment = array_replace(getenv(), [
        'APP_ENV' => 'testing', 'AIS_MYSQL_TESTS' => '1', 'AIS_MYSQL_TEST_TARGET' => 'ais_test',
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_DATABASE' => 'ais_test',
        'DB_USERNAME' => 'ais_tester', 'DB_PASSWORD' => DRILL_SECRET,
    ], $overrides);
    return array_filter($environment, static fn (mixed $v): bool => $v !== null);
}

function drillOfflineBin(string $root): string
{
    $bin = $root . DIRECTORY_SEPARATOR . 'bin';
    mkdir($bin, 0700);
    foreach (['mariadb', 'mariadb-dump'] as $name) {
        file_put_contents($bin . DIRECTORY_SEPARATOR . $name . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''), '');
    }
    return $bin;
}

function drillOfflineRun(array $arguments, array $overrides = []): array
{
    return runProcess(array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/database/tools/backup_drill.php'], $arguments), drillOfflineEnv($overrides));
}

test('DB-33 drill refuses with exit 2, writes nothing and leaks no secret when a guard fails', static function (): void {
    $root = tempDirectory();
    $bin = drillOfflineBin($root);
    $dump = $root . DIRECTORY_SEPARATOR . 'dump';
    $good = ['--confirm-destructive-ais-test', '--dump-dir=' . $dump, '--mariadb-bin=' . $bin];
    $cases = [
        'no confirm flag' => [[$good[1], $good[2]], []],
        'extra argument' => [array_merge($good, ['--x']), []],
        'duplicate dump-dir' => [array_merge($good, ['--dump-dir=' . $dump . '2']), []],
        'relative dump dir' => [['--confirm-destructive-ais-test', '--dump-dir=dump', $good[2]], []],
        'traversal dump dir' => [['--confirm-destructive-ais-test', '--dump-dir=' . $root . '/a/../dump', $good[2]], []],
        'missing binaries' => [['--confirm-destructive-ais-test', $good[1], '--mariadb-bin=' . $root], []],
        'relative bin dir' => [['--confirm-destructive-ais-test', $good[1], '--mariadb-bin=bin'], []],
        'APP_ENV' => [$good, ['APP_ENV' => 'production']],
        'tests flag' => [$good, ['AIS_MYSQL_TESTS' => '0']],
        'target' => [$good, ['AIS_MYSQL_TEST_TARGET' => 'other']],
        'database' => [$good, ['DB_DATABASE' => 'ais']],
        'host name' => [$good, ['DB_HOST' => 'localhost']],
        'host ip' => [$good, ['DB_HOST' => '10.0.0.5']],
        'account' => [$good, ['DB_USERNAME' => 'root']],
        'app account' => [$good, ['DB_USERNAME' => 'ais_test_app']],
        'port' => [$good, ['DB_PORT' => '99999']],
        'no password' => [$good, ['DB_PASSWORD' => null]],
        'newline password' => [$good, ['DB_PASSWORD' => "a\nb" . DRILL_SECRET]],
    ];
    // M2: the other guards pass for these, so only the location guard can refuse them (exit 2, nothing created).
    $oneDriveParent = $root . DIRECTORY_SEPARATOR . 'OneDrive';
    mkdir($oneDriveParent, 0700);
    $oneDriveDump = $oneDriveParent . DIRECTORY_SEPARATOR . 'dump';
    $repoDump = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'drill-m2-' . bin2hex(random_bytes(4));
    try {
        foreach (['onedrive dump dir' => $oneDriveDump, 'in-repo dump dir' => $repoDump] as $label => $target) {
            [$exit, $out, $err] = drillOfflineRun(['--confirm-destructive-ais-test', '--dump-dir=' . $target, $good[2]]);
            same(2, $exit);
            check(!str_contains($out . $err, DRILL_SECRET), 'Secret leaked: ' . $label);
            check(!file_exists($target), 'Dump dir created: ' . $label);
        }
        same(['.', '..'], scandir($oneDriveParent));
        rmdir($oneDriveParent);
        foreach ($cases as $label => [$arguments, $overrides]) {
            [$exit, $out, $err] = drillOfflineRun($arguments, $overrides);
            same(2, $exit);
            check(!str_contains($out . $err, DRILL_SECRET), 'Secret leaked: ' . $label);
            check(!file_exists($dump), 'Dump dir created: ' . $label);
            same(['.', '..', 'bin'], scandir($root));
        }
        // non-empty existing dump dir is refused and left untouched
        mkdir($dump, 0700);
        file_put_contents($dump . '/keep', 'x');
        same(2, drillOfflineRun($good)[0]);
        same(['.', '..', 'keep'], scandir($dump));
    } finally {
        // Only the exact path this test chose, and only if a regressed guard created it.
        if (str_starts_with(basename($repoDump), 'drill-m2-') && is_dir($repoDump)) {
            removeTestDirectory($repoDump);
        }
        removeTestDirectory($root);
    }
});
