<?php
declare(strict_types=1);

use App\Auth\Repositories\MockUserRepository;
use App\Core\JsonStore;

function authTestRows(): array
{
    static $hash = null;
    $hash ??= password_hash('Synthetic-test-pass-42', PASSWORD_DEFAULT);
    return [
        ['id' => 1, 'email' => 'student@example.test', 'roles' => ['student'], 'status' => 'active', 'password_hash' => $hash, 'auth_version' => 1],
        ['id' => 2, 'email' => 'inactive@example.test', 'roles' => ['faculty'], 'status' => 'inactive', 'password_hash' => $hash, 'auth_version' => 1],
    ];
}

final class AuthHttpServer
{
    private mixed $process = null;
    private string $cookie = '';
    public readonly string $directory;
    private readonly string $url;

    /** $router and $rows are optional so T-01 callers are unchanged (T-02 uses its own probe router and users). */
    public function __construct(array $overrides = [], string $router = 'public/router.php', ?array $rows = null)
    {
        $this->directory = tempDirectory();
        try {
            (new MockUserRepository(new JsonStore($this->directory . '/auth/users.json')))->seed($rows ?? authTestRows());
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            check(is_resource($socket), 'Cannot reserve auth test port.');
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $this->url = 'http://' . $address;
            $environment = array_merge(getenv(), [
                'APP_ENV' => 'testing',
                'APP_REPOSITORY' => 'mock',
                'APP_RUNTIME_PATH' => $this->directory,
            ], $overrides);
            $this->process = proc_open(
                [PHP_BINARY, '-d', 'display_errors=0', '-d', 'display_startup_errors=0', '-d', 'upload_tmp_dir=' . $this->directory, '-S', $address, $router],
                [0 => ['pipe', 'r'], 1 => ['file', $this->directory . '/stdout.log', 'w'], 2 => ['file', $this->directory . '/stderr.log', 'w']],
                $pipes,
                dirname(__DIR__, 2),
                $environment,
                ['bypass_shell' => true]
            );
            check(is_resource($this->process), 'Cannot launch auth test server.');
            fclose($pipes[0]);
            $deadline = microtime(true) + 5;
            do {
                $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
                if (is_resource($connection)) {
                    fclose($connection);
                    return;
                }
                check(proc_get_status($this->process)['running'], 'Auth test server exited.');
                usleep(20000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('Auth test server did not become ready.');
        } catch (Throwable $exception) {
            $this->close();
            throw $exception;
        }
    }

    public function request(string $method, string $path, ?array $payload = null, ?string $csrf = null, array $extraHeaders = []): array
    {
        $headers = ['Connection: close', 'Content-Type: application/json'];
        if ($this->cookie !== '') {
            $headers[] = 'Cookie: ' . $this->cookie;
        }
        if ($csrf !== null) {
            $headers[] = 'X-CSRF-Token: ' . $csrf;
        }
        foreach ($extraHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $payload === null ? '' : json_encode((object) $payload, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 5,
        ]]);
        $body = @file_get_contents($this->url . $path, false, $context);
        check($body !== false, 'Auth HTTP request failed.');
        check(isset($http_response_header[0]), 'Missing HTTP status line.');
        preg_match('~^HTTP/\S+ (\d+)~', $http_response_header[0], $match);
        $responseHeaders = [];
        $setCookies = [];
        foreach (array_slice($http_response_header, 1) as $header) {
            $parts = explode(':', $header, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $name = strtolower($parts[0]);
            $value = trim($parts[1]);
            $responseHeaders[$name] = $value;
            if ($name === 'set-cookie') {
                $setCookies[] = $value;
                if (str_starts_with($value, 'AIS_SESSION=')) {
                    $this->cookie = str_contains(strtolower($value), 'max-age=0') ? '' : explode(';', $value, 2)[0];
                }
            }
        }
        return [
            'status' => (int) $match[1],
            'headers' => $responseHeaders,
            'set_cookies' => $setCookies,
            'body' => $body,
            'json' => json_decode($body, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    public function cookie(): string
    {
        return $this->cookie;
    }

    public function setCookie(string $cookie): void
    {
        $this->cookie = $cookie;
    }

    public function csrf(): string
    {
        $response = $this->request('GET', '/api/auth/csrf');
        same(200, $response['status']);
        $token = $response['json']['data']['csrf_token'] ?? null;
        check(is_string($token) && strlen($token) >= 32, 'Missing random CSRF token.');
        return $token;
    }

    public function login(string $email = 'student@example.test'): array
    {
        return $this->request('POST', '/api/auth/login', [
            'email' => $email,
            'password' => 'Synthetic-test-pass-42',
        ], $this->csrf());
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
