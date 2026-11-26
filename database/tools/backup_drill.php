<?php
declare(strict_types=1);

/**
 * DB-33 backup/restore drill, entirely inside ais_test. UNVERIFIED (never executed by its author).
 * DESTRUCTIVE on the TEST database only: it drops every ais_test table, then restores from a dump.
 *
 * php database/tools/backup_drill.php --confirm-destructive-ais-test --dump-dir=<abs new dir> --mariadb-bin=<abs dir>
 * Needs env: APP_ENV=testing AIS_MYSQL_TESTS=1 AIS_MYSQL_TEST_TARGET=ais_test DB_HOST=127.0.0.1 DB_PORT DB_DATABASE=ais_test
 *            DB_USERNAME=ais_tester DB_PASSWORD (env only; never on a command line).
 * Exit: 0 identical, 1 drill failed (ais_test may be partially restored; dump is kept), 2 refused before any process/connection.
 */

use App\Core\Database;

function drillRefuse(): never
{
    fwrite(STDERR, "Drill refused.\n");
    exit(2);
}

function drillAbsolute(string $path): bool
{
    return preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) === 1
        && !in_array('..', preg_split('~[\\\\/]+~', $path) ?: ['..'], true)
        && !str_contains($path, "\0");
}

/** Pure checks only: no connection, no process, no filesystem write. Returns [dumpDir, binDir]. */
function drillGuards(array $argv): array
{
    if (PHP_SAPI !== 'cli') { drillRefuse(); }
    $confirmed = false;
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--confirm-destructive-ais-test') { $confirmed = true; continue; }
        if (preg_match('~^--(dump-dir|mariadb-bin)=(.+)$~s', $argument, $m) !== 1 || isset($options[$m[1]])) { drillRefuse(); }
        $options[$m[1]] = $m[2];
    }
    $port = getenv('DB_PORT');
    if (!$confirmed || count($options) !== 2
        || getenv('APP_ENV') !== 'testing'
        || getenv('AIS_MYSQL_TESTS') !== '1'
        || getenv('AIS_MYSQL_TEST_TARGET') !== 'ais_test'
        || getenv('DB_DATABASE') !== 'ais_test'
        || getenv('DB_HOST') !== '127.0.0.1'
        || getenv('DB_USERNAME') !== 'ais_tester'
        || !is_string($port) || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
        || !is_string(getenv('DB_PASSWORD')) || getenv('DB_PASSWORD') === ''
        || preg_match('~[\r\n\0]~', (string) getenv('DB_PASSWORD')) === 1) {
        drillRefuse();
    }
    $dumpDir = $options['dump-dir'];
    $binDir = rtrim($options['mariadb-bin'], '\\/');
    if (!drillAbsolute($dumpDir) || !drillAbsolute($binDir)) { drillRefuse(); }
    if (file_exists($dumpDir) && (!is_dir($dumpDir) || (scandir($dumpDir) ?: ['x']) !== ['.', '..'])) { drillRefuse(); }
    if (!is_dir(dirname($dumpDir))) { drillRefuse(); }
    // M2: the dump holds all data; refuse synced (OneDrive) and in-repo locations. Resolve the parent so a symlink cannot bypass it.
    $parent = realpath(dirname($dumpDir));
    $repo = realpath(dirname(__DIR__, 2));
    if ($parent === false || $repo === false) { drillRefuse(); }
    $resolved = str_replace('\\', '/', rtrim($parent, '\\/')) . '/' . basename($dumpDir);
    $repoNorm = str_replace('\\', '/', rtrim($repo, '\\/'));
    if (stripos($dumpDir, 'onedrive') !== false || stripos($resolved, 'onedrive') !== false
        || strcasecmp($resolved, $repoNorm) === 0 || stripos($resolved . '/', $repoNorm . '/') === 0) { drillRefuse(); }
    foreach (['mariadb', 'mariadb-dump'] as $name) {
        if (!is_file($binDir . DIRECTORY_SEPARATOR . $name . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''))) { drillRefuse(); }
    }
    return [$dumpDir, $binDir];
}

if (PHP_SAPI !== 'cli') { http_response_code(403); exit(2); }
[$dumpDir, $binDir] = drillGuards($argv);

require dirname(__DIR__, 2) . '/app/autoload.php';
require dirname(__DIR__, 2) . '/tests/Database/MySQLTestSupport.php';

/** @return array{0:int,1:string} exit code, stdout */
function drillRun(array $command, ?string $stdinFile = null, ?string $stdoutFile = null): array
{
    $spec = [0 => $stdinFile === null ? ['pipe', 'r'] : ['file', $stdinFile, 'r'],
        1 => $stdoutFile === null ? ['pipe', 'w'] : ['file', $stdoutFile, 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $spec, $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
    if (!is_resource($process)) { throw new RuntimeException('Process failed.'); }
    // Trusted run 2026-11-26: closing pipe 0 here and again in the loop below threw a TypeError (PHP 8) before step 1.
    if (isset($pipes[0])) { fclose($pipes[0]); unset($pipes[0]); }
    $out = isset($pipes[1]) ? (string) stream_get_contents($pipes[1]) : '';
    stream_get_contents($pipes[2]); // discarded: never echo client stderr
    foreach ($pipes as $pipe) { fclose($pipe); }
    return [proc_close($process), $out];
}

/** @return array<string,array{rows:int,hash:string}> */
function drillSnapshot(PDO $pdo): array
{
    MySQLTestSupport::assertConnection($pdo);
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'ais_test' AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $snapshot = [];
    foreach ($tables as $table) {
        if (preg_match('~^[a-z_0-9]+$~', (string) $table) !== 1) { throw new RuntimeException('Table name refused.'); }
        $keys = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'ais_test' AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION");
        $keys->execute([$table]);
        $pk = $keys->fetchAll(PDO::FETCH_COLUMN);
        if ($pk === []) { throw new RuntimeException('Table without primary key.'); }
        $order = implode(', ', array_map(static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`', $pk));
        $hash = hash_init('sha256');
        $rows = 0;
        foreach ($pdo->query('SELECT * FROM `ais_test`.`' . $table . '` ORDER BY ' . $order, PDO::FETCH_NUM) as $row) {
            hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) . "\n");
            $rows++;
        }
        $snapshot[$table] = ['rows' => $rows, 'hash' => hash_final($hash)];
    }
    return $snapshot;
}

function drillStep(string $label): void { echo $label . PHP_EOL; }

$optionFile = $dumpDir . DIRECTORY_SEPARATOR . 'client.cnf';
$destructive = false;
try {
    umask(0077);
    if (!is_dir($dumpDir) && !mkdir($dumpDir, 0700)) { throw new RuntimeException('Dump dir failed.'); }
    $password = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) getenv('DB_PASSWORD'));
    $handle = fopen($optionFile, 'xb');
    if ($handle === false) { throw new RuntimeException('Option file failed.'); }
    chmod($optionFile, 0600); // POSIX only; on Windows the dump dir's inherited ACL applies
    fwrite($handle, "[client]\nuser=ais_tester\npassword=\"" . $password . "\"\nhost=127.0.0.1\nport=" . getenv('DB_PORT') . "\n");
    fclose($handle);
    $ext = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
    $client = $binDir . DIRECTORY_SEPARATOR . 'mariadb' . $ext;
    $dumper = $binDir . DIRECTORY_SEPARATOR . 'mariadb-dump' . $ext;
    $defaults = '--defaults-extra-file=' . $optionFile; // must be the first client argument
    $dump = $dumpDir . DIRECTORY_SEPARATOR . 'dump.sql';
    $verify = [PHP_BINARY, dirname(__DIR__) . '/verify.php', '--target=ais_test'];

    [, $version] = drillRun([$client, '--version']);
    if (stripos($version, 'mariadb') === false) { throw new RuntimeException('Client refused.'); }

    $pdo = MySQLTestSupport::connect();
    $account = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
    if ($account !== 'ais_tester@127.0.0.1') { throw new RuntimeException('Account refused.'); }

    drillStep('1 verify (before)');
    if (drillRun($verify)[0] !== 0) { throw new RuntimeException('Verify before failed.'); }
    $before = drillSnapshot($pdo);
    foreach (['schema_migrations', 'repository_locks'] as $required) {
        if (!isset($before[$required])) { throw new RuntimeException('Expected table missing.'); }
    }

    drillStep('2 dump');
    if (drillRun([$dumper, $defaults, '--single-transaction', '--routines=0', '--triggers=0', '--no-tablespaces', 'ais_test'], null, $dump)[0] !== 0
        || !is_file($dump) || filesize($dump) < 1) { throw new RuntimeException('Dump failed.'); }

    drillStep('3 reset ais_test (destructive, test database only)');
    $destructive = true;
    $account = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
    if ($account !== 'ais_tester@127.0.0.1') { throw new RuntimeException('Account refused.'); }
    MySQLTestSupport::reset($pdo); // re-asserts DATABASE()/port/account before every DROP
    $remaining = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'ais_test'")->fetchColumn();
    echo 'tables after reset ' . $remaining . PHP_EOL;

    drillStep('4 restore');
    if (drillRun([$client, $defaults, 'ais_test'], $dump)[0] !== 0) { throw new RuntimeException('Restore failed.'); }

    drillStep('5 verify (after) and compare');
    $pdo = MySQLTestSupport::connect();
    if (drillRun($verify)[0] !== 0) { throw new RuntimeException('Verify after failed.'); }
    $after = drillSnapshot($pdo);
    $identical = $remaining === 0 && array_keys($before) === array_keys($after);
    foreach ($before as $table => $state) {
        $match = isset($after[$table]) && $after[$table] === $state;
        $identical = $identical && $match;
        echo str_pad($table, 32) . ' before=' . $state['rows'] . ' after=' . ($after[$table]['rows'] ?? 'missing')
            . ' content=' . ($match ? 'SAME' : 'DIFF') . PHP_EOL;
    }
    echo 'extra tables after restore: ' . count(array_diff_key($after, $before)) . PHP_EOL;
    echo ($identical ? 'DRILL PASS' : 'DRILL FAIL') . PHP_EOL;
    $code = $identical ? 0 : 1;
} catch (Throwable) {
    fwrite(STDERR, 'Drill error.' . ($destructive ? ' ais_test may be partially restored; dump kept in dump dir.' : '') . "\n");
    $code = 1;
} finally {
    if (is_file($optionFile)) { @unlink($optionFile); } // holds the password
}
exit($code);
