<?php
declare(strict_types=1);

use App\Core\JsonStore;

test('JsonStore commits and returns callback values', function (): void {
    $directory = tempDirectory();
    try {
        $store = new JsonStore($directory . '/state.json');
        same([], $store->read());
        $result = $store->transaction(function (array &$data): int {
            $data = ['count' => 1, 'rows' => [['id' => 1]]];
            return 42;
        });
        same(42, $result);
        same(['count' => 1, 'rows' => [['id' => 1]]], (new JsonStore($directory . '/state.json'))->read());
    } finally {
        removeTestDirectory($directory);
    }
});

test('JsonStore rolls back callback and serialization failures and releases locks', function (): void {
    $directory = tempDirectory();
    try {
        $store = new JsonStore($directory . '/state.json');
        $store->transaction(function (array &$data): void { $data['count'] = 1; });
        throws(RuntimeException::class, function () use ($store): void {
            $store->transaction(function (array &$data): void {
                $data['count'] = 999;
                throw new RuntimeException('rollback');
            });
        });
        same(['count' => 1], $store->read());
        throws(RuntimeException::class, function () use ($store): void {
            $store->transaction(function (array &$data): void { $data['bad'] = "\xFF"; });
        });
        same(['count' => 1], $store->read());
        $store->transaction(function (array &$data): void { $data['count']++; });
        same(['count' => 2], $store->read());
    } finally {
        removeTestDirectory($directory);
    }
});

test('JsonStore rejects corruption and nested operations without overwriting data', function (): void {
    $directory = tempDirectory();
    try {
        $path = $directory . '/state.json';
        $store = new JsonStore($path);
        foreach (['', '{broken', 'null', '42'] as $contents) {
            file_put_contents($path, $contents);
            throws(RuntimeException::class, fn () => $store->read());
            throws(RuntimeException::class, fn () => $store->transaction(function (array &$data): void {
                $data['replacement'] = true;
            }));
            same($contents, file_get_contents($path));
        }
        file_put_contents($path, '{}');
        throws(RuntimeException::class, fn () => $store->transaction(fn () => $store->read()));
        same([], $store->read());
    } finally {
        removeTestDirectory($directory);
    }
});

test('JsonStore serializes concurrent process updates without lost writes', function (): void {
    $directory = tempDirectory();
    $workers = [];
    try {
        $store = new JsonStore($directory . '/state.json');
        $store->transaction(function (array &$data): void { $data['count'] = 0; });
        for ($index = 0; $index < 4; $index++) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/store_worker.php',
                $directory . '/state.json', $directory . '/start', $directory . '/ready-' . $index],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            check(is_resource($process), 'Unable to start store worker');
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 10;
        do {
            $ready = count(glob($directory . '/ready-*'));
            if ($ready === 4) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        same(4, $ready);
        file_put_contents($directory . '/start', 'go');
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            same(0, proc_close($process));
            same('', $errors);
            same('50 updates' . PHP_EOL, $output);
        }
        $workers = [];
        same(['count' => 200], $store->read());
    } finally {
        foreach ($workers as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_close($process);
            }
        }
        removeTestDirectory($directory);
    }
});
