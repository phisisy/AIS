<?php
declare(strict_types=1);

final class CoreHttpServer
{
    private mixed $process = null;
    private string $directory;
    public readonly string $url;

    public function __construct(array $overrides = [], bool $configureStartup = true)
    {
        $this->directory = tempDirectory();
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        check(is_resource($socket), 'Cannot reserve local test port.');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->url = 'http://' . $address;
        $environment = array_merge(getenv(), [
            'APP_REPOSITORY' => 'mock',
            'APP_RUNTIME_PATH' => $this->directory . '/runtime',
        ], $overrides);
        $command = [PHP_BINARY];
        if ($configureStartup) {
            array_push($command, '-d', 'display_errors=0', '-d', 'display_startup_errors=0', '-d', 'upload_tmp_dir=' . $this->directory);
        }
        array_push($command, '-S', $address, 'public/router.php');
        $this->process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['file', $this->directory . '/stdout.log', 'w'], 2 => ['file', $this->directory . '/stderr.log', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $environment,
            ['bypass_shell' => true]
        );
        try {
            check(is_resource($this->process), 'Cannot launch PHP test server.');
            fclose($pipes[0]);
            $deadline = microtime(true) + 5;
            do {
                $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
                if (is_resource($connection)) {
                    fclose($connection);
                    return;
                }
                check(proc_get_status($this->process)['running'], 'PHP test server exited.');
                usleep(20000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('PHP test server did not become ready.');
        } catch (Throwable $exception) {
            $this->close();
            throw $exception;
        }
    }

    public function request(string $method, string $path, string $body = '', ?string $type = null): array
    {
        $headers = ['Connection: close'];
        if ($type !== null) {
            $headers[] = 'Content-Type: ' . $type;
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 5,
        ]]);
        $result = @file_get_contents($this->url . $path, false, $context);
        check($result !== false, 'HTTP test request failed.');
        check(isset($http_response_header[0]), 'Missing HTTP status line.');
        preg_match('~^HTTP/\S+ (\d+)~', $http_response_header[0], $match);
        $responseHeaders = [];
        foreach (array_slice($http_response_header, 1) as $header) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower($parts[0])] = trim($parts[1]);
            }
        }
        return [(int) $match[1], $responseHeaders, $result];
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        if (is_dir($this->directory)) {
            removeTestDirectory($this->directory);
        }
    }
}

test('HTTP documented bare php -S command serves the root endpoint', function (): void {
    $server = new CoreHttpServer([], false);
    try {
        [$status, $headers, $body] = $server->request('GET', '/');
        same(200, $status);
        same('application/json', $headers['content-type']);
        same(true, json_decode($body)->success);
    } finally {
        $server->close();
    }
});

test('HTTP front controller serves root, 404 and 405 with JSON security headers', function (): void {
    $server = new CoreHttpServer();
    try {
        [$status, $headers, $body] = $server->request('GET', '/?probe=1');
        same(200, $status);
        same('application/json', $headers['content-type']);
        same('no-store', $headers['cache-control']);
        same('nosniff', $headers['x-content-type-options']);
        $json = json_decode($body);
        same(true, $json->success);
        check($json->data instanceof stdClass);
        same(404, $server->request('GET', '/unknown')[0]);
        [$status, $headers, $body] = $server->request('POST', '/');
        same(405, $status);
        same('GET', $headers['allow']);
        same(false, json_decode($body)->success);
    } finally {
        $server->close();
    }
});

test('HTTP request parser enforces 415, 400, 413 and the size boundary', function (): void {
    $server = new CoreHttpServer();
    try {
        foreach (['text/plain', 'application/x-www-form-urlencoded', 'multipart/form-data; boundary=test'] as $type) {
            same(415, $server->request('POST', '/', 'value=data', $type)[0]);
        }
        same(400, $server->request('POST', '/', '{', 'application/json')[0]);
        same(413, $server->request('POST', '/', str_repeat('a', 65537), 'application/json')[0]);
        $valid = '{"x":"' . str_repeat('a', 65536 - 8) . '"}';
        same(405, $server->request('POST', '/', $valid, 'application/json; charset=utf-8')[0]);
    } finally {
        $server->close();
    }
});

test('HTTP built-in router never exposes repository or dot files', function (): void {
    $server = new CoreHttpServer();
    try {
        foreach (['/.env', '/.env.example', '/.git/config', '/AGENTS.md', '/README.md', '/app/bootstrap.php', '/config/database.php', '/tests/run.php', '/storage/runtime/store.json'] as $path) {
            [$status, $headers, $body] = $server->request('GET', $path);
            same(404, $status);
            same('application/json', $headers['content-type']);
            same('Not found.', json_decode($body)->message);
        }
    } finally {
        $server->close();
    }
});

test('HTTP bootstrap failure returns generic 500 without configuration leakage', function (): void {
    $server = new CoreHttpServer(['APP_REPOSITORY' => 'invalid-secret-value']);
    try {
        [$status, $headers, $body] = $server->request('GET', '/');
        same(500, $status);
        same('application/json', $headers['content-type']);
        same('{"success":false,"message":"An internal error occurred.","errors":{}}', $body);
        check(!str_contains($body, 'invalid-secret-value'));
    } finally {
        $server->close();
    }
});
