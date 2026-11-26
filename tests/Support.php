<?php
declare(strict_types=1);

$GLOBALS['ais_tests'] = [];
$GLOBALS['ais_test_directories'] = [];

function test(string $name, callable $action): void
{
    $GLOBALS['ais_tests'][] = [$name, $action];
}

function check(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function same(mixed $expected, mixed $actual): void
{
    check($expected === $actual, 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function throws(string $class, callable $action): Throwable
{
    try {
        $action();
    } catch (Throwable $exception) {
        check($exception instanceof $class, 'Expected ' . $class . ', got ' . get_class($exception));
        return $exception;
    }
    throw new RuntimeException('Expected exception ' . $class);
}

function tempDirectory(): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ais-test-' . bin2hex(random_bytes(12));
    if (!mkdir($path, 0700)) {
        throw new RuntimeException('Cannot create private test directory.');
    }
    $GLOBALS['ais_test_directories'][realpath($path)] = true;
    return realpath($path);
}

function removeTestDirectory(string $path): void
{
    $resolved = realpath($path);
    if ($resolved === false || !isset($GLOBALS['ais_test_directories'][$resolved]) || is_link($path)) {
        throw new RuntimeException('Refusing to remove an unowned test directory.');
    }
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($entries as $entry) {
        $target = $entry->getPathname();
        if ($entry->isLink()) {
            unlink($target);
            continue;
        }
        $actual = realpath($target);
        if ($actual === false || !str_starts_with($actual, $resolved . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Test cleanup escaped its private directory.');
        }
        if ($entry->isDir()) {
            rmdir($target);
        } else {
            unlink($target);
        }
    }
    rmdir($resolved);
    unset($GLOBALS['ais_test_directories'][$resolved]);
}

function runProcess(array $command, ?array $environment = null): array
{
    $directory = tempDirectory();
    $stdout = $directory . '/stdout';
    $stderr = $directory . '/stderr';
    $process = null;
    try {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']], $pipes, dirname(__DIR__), $environment, ['bypass_shell' => true]);
        check(is_resource($process), 'Unable to start test process.');
        fclose($pipes[0]);
        $deadline = microtime(true) + 20;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                throw new RuntimeException('Test process timed out.');
            }
            usleep(10000);
        } while (true);
        $exit = $status['exitcode'];
        $closed = proc_close($process);
        $process = null;
        return [$exit === -1 ? $closed : $exit, file_get_contents($stdout), file_get_contents($stderr)];
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        removeTestDirectory($directory);
    }
}
