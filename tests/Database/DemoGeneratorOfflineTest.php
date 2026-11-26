<?php
declare(strict_types=1);

// Offline: exercises ONLY the guard refusals of database/demo/generate_demo_data.php (exit 2, no files, no secrets).
// The orchestration itself needs a trusted MySQL machine and is not covered here. UNVERIFIED.

function demoGenRun(array $args, array $env): array
{
    $p = proc_open(array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/database/demo/generate_demo_data.php'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), array_replace(getenv(), $env));
    check(is_resource($p));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
}

test('Demo generator offline: every guard refuses with exit 2, writes nothing and leaks no secret', static function (): void {
    $secret = 'sekret-' . bin2hex(random_bytes(6));
    $good = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'ais_test', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1',
        'DB_MIGRATION_USERNAME' => 'ais_tester', 'DB_MIGRATION_PASSWORD' => $secret, 'AIS_MYSQL_TEST_APP_PASSWORD' => $secret];
    $runtime = tempDirectory();
    $repoRuntime = dirname(__DIR__, 2) . '/demo-l2-' . bin2hex(random_bytes(4));
    try {
        $ok = ['--runtime=' . $runtime, '--confirm-ais-test'];
        $cases = [
            'env APP_ENV' => [$ok, ['APP_ENV' => 'development']],
            'env database' => [$ok, ['DB_DATABASE' => 'ais']],
            'env host' => [$ok, ['DB_HOST' => 'localhost']],
            'env tester' => [$ok, ['DB_MIGRATION_USERNAME' => 'root']],
            'env app password' => [$ok, ['AIS_MYSQL_TEST_APP_PASSWORD' => '']],
            'no confirm' => [['--runtime=' . $runtime], []],
            'no runtime' => [['--confirm-ais-test'], []],
            'unknown arg' => [array_merge($ok, ['--x']), []],
            'relative runtime' => [['--runtime=relative/dir', '--confirm-ais-test'], []],
            'onedrive runtime' => [['--runtime=C:/Users/x/OneDrive/demo', '--confirm-ais-test'], []],
            // L2: public/ and in-repo targets. NOTE: when the checkout itself lives under OneDrive (this machine) the onedrive
            // guard refuses these first, so here they only prove exit 2 / nothing created; on a non-OneDrive checkout they
            // exercise the repo-root guard (public/ is under the root) alone.
            'public runtime' => [['--runtime=' . dirname(__DIR__, 2) . '/public/demo', '--confirm-ais-test'], []],
            'in-repo runtime' => [['--runtime=' . $repoRuntime, '--confirm-ais-test'], []],
        ];
        foreach ($cases as $name => [$args, $override]) {
            [$code, $output] = demoGenRun($args, array_replace($good, $override));
            check($code === 2, $name . ': exit ' . $code);
            check(!str_contains($output, $secret), $name . ': secret in output');
            check(scandir($runtime) === ['.', '..'], $name . ': runtime touched');
            check(!file_exists($repoRuntime) && !file_exists(dirname(__DIR__, 2) . '/public/demo'), $name . ': in-repo path created');
        }
        file_put_contents($runtime . '/x', '');
        [$code] = demoGenRun($ok, $good);
        check($code === 2, 'non-empty runtime refused');
        check(scandir($runtime) === ['.', '..', 'x'], 'non-empty runtime untouched');
    } finally {
        // Only the exact path this test chose, and only if a regressed guard created it.
        if (str_starts_with(basename($repoRuntime), 'demo-l2-') && is_dir($repoRuntime)) {
            removeTestDirectory($repoRuntime);
        }
        removeTestDirectory($runtime);
    }
});
