<?php
declare(strict_types=1);

// Optional XAMPP acceptance check, separate from the portable offline suite.
// Uses only a private config and a temporary loopback listener, never a service.
$project = dirname(__DIR__, 2);
require_once $project . '/app/autoload.php'; // App\Core\Environment::forChild for the N1 blanks below
$apacheRoot = str_replace('\\', '/', getenv('AIS_TEST_APACHE_ROOT') ?: 'C:/xampp/apache');
$phpRoot = str_replace('\\', '/', dirname(PHP_BINARY));
$apache = $apacheRoot . '/bin/httpd.exe';
if (!is_file($apache)) {
    fwrite(STDERR, "Apache check unavailable: set AIS_TEST_APACHE_ROOT to the XAMPP Apache directory.\n");
    exit(1);
}

function apacheCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function apacheRequest(int $port, string $path, string $method = 'GET', string $body = '', string $type = 'application/json'): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => "Content-Type: {$type}\r\nConnection: close\r\n",
        'content' => $body,
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 3,
    ]]);
    $content = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
    apacheCheck($content !== false, 'HTTP request failed.');
    $headers = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $headers[0] ?? '', $match);
    return [(int) ($match[1] ?? 0), $headers, $content];
}

/**
 * T-60 Phase 7.1 (owner OQ-D): optional Apache + MySQL probe. Off by default; runs only when
 * AIS_APACHE_MYSQL_PROBE=1 AND the guarded test-only MySQL environment is present (ais_test, loopback,
 * app account ais_test_app). It never resets or migrates anything; ais_test must already be migrated.
 */
function apacheMysqlProbeEnvironment(): ?array
{
    if (getenv('AIS_APACHE_MYSQL_PROBE') !== '1') {
        return null;
    }
    $password = getenv('AIS_MYSQL_TEST_APP_PASSWORD');
    apacheCheck(getenv('AIS_MYSQL_TESTS') === '1' && getenv('APP_ENV') === 'testing'
        && getenv('DB_DATABASE') === 'ais_test' && getenv('AIS_MYSQL_TEST_TARGET') === 'ais_test'
        && in_array(getenv('DB_HOST'), ['127.0.0.1'], true)
        && ctype_digit((string) getenv('DB_PORT')) && is_string($password) && $password !== '',
        'Apache MySQL probe refused: the guarded test-only MySQL environment is required.');
    return ['DB_HOST' => (string) getenv('DB_HOST'), 'DB_PORT' => (string) getenv('DB_PORT'),
        'DB_DATABASE' => 'ais_test', 'DB_USERNAME' => 'ais_test_app', 'DB_PASSWORD' => $password];
}

/** Request with optional headers and a simple cookie jar (probe only). */
function apacheProbeRequest(int $port, string $method, string $path, ?array $json, array &$jar, array $headers = []): array
{
    if ($jar !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(static fn ($k, $v) => $k . '=' . $v, array_keys($jar), $jar));
    }
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $headers[] = 'Connection: close';
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers) . "\r\n",
        'content' => $json === null ? '' : json_encode($json), 'ignore_errors' => true, 'timeout' => 15]]);
    $content = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
    apacheCheck($content !== false, 'Probe HTTP request failed.');
    $received = $http_response_header ?? [];
    foreach ($received as $line) {
        if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $line, $cookie)) {
            $jar[$cookie[1]] = $cookie[2];
        }
    }
    preg_match('/\s(\d{3})\s/', $received[0] ?? '', $match);
    return [(int) ($match[1] ?? 0), $content];
}

$temporary = str_replace('\\', '/', __DIR__) . '/.apache-runtime-' . bin2hex(random_bytes(10));
apacheCheck(mkdir($temporary, 0700), 'Unable to create private Apache runtime.');
$createdFiles = [];
$process = null;
$exit = 0;
try {
    foreach (['public' => $project . '/public', 'repository' => $project] as $mode => $documentRoot) {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        apacheCheck($socket !== false, 'Unable to reserve a loopback port.');
        $address = stream_socket_get_name($socket, false);
        $port = (int) substr(strrchr($address, ':'), 1);
        fclose($socket);
        $private = str_replace('\\', '/', $temporary);
        $documentRoot = str_replace('\\', '/', $documentRoot);
        $config = $temporary . '/' . $mode . '.conf';
        $log = $temporary . '/' . $mode . '.log';
        $stdout = $temporary . '/' . $mode . '-stdout.log';
        $stderr = $temporary . '/' . $mode . '-stderr.log';
        $pid = $temporary . '/' . $mode . '.pid';
        array_push($createdFiles, $config, $log, $stdout, $stderr, $pid);
        $configuration = <<<CONF
ServerRoot "{$apacheRoot}"
Listen 127.0.0.1:{$port}
ServerName 127.0.0.1
PidFile "{$private}/{$mode}.pid"
ErrorLog "{$private}/{$mode}.log"
LogLevel warn
ServerTokens Prod
ServerSignature Off
LoadModule authz_core_module "{$apacheRoot}/modules/mod_authz_core.so"
LoadModule authz_host_module "{$apacheRoot}/modules/mod_authz_host.so"
LoadModule dir_module "{$apacheRoot}/modules/mod_dir.so"
LoadModule mime_module "{$apacheRoot}/modules/mod_mime.so"
LoadModule rewrite_module "{$apacheRoot}/modules/mod_rewrite.so"
LoadFile "{$phpRoot}/php8ts.dll"
LoadFile "{$phpRoot}/libsqlite3.dll"
LoadModule php_module "{$phpRoot}/php8apache2_4.dll"
PHPINIDir "{$phpRoot}"
TypesConfig "{$apacheRoot}/conf/mime.types"
DocumentRoot "{$documentRoot}"
<Directory />
    AllowOverride None
    Require all denied
</Directory>
<Directory "{$documentRoot}">
    AllowOverride All
    Options FollowSymLinks
    Require all granted
</Directory>
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>

CONF;
        apacheCheck(file_put_contents($config, $configuration) !== false, 'Unable to write private Apache config.');
        // Empty process values win over .env (Environment::get), so no parent secret reaches mock-mode Apache.
        $environment = array_replace(getenv(), ['DB_PASSWORD' => '', 'AIS_MYSQL_TEST_APP_PASSWORD' => '',
            'DB_MIGRATION_USERNAME' => '', 'DB_MIGRATION_PASSWORD' => '']);
        $environment['APP_REPOSITORY'] = 'mock';
        $environment['APP_ENV'] = 'testing';
        $environment['APP_RUNTIME_PATH'] = $temporary;
        $environment = App\Core\Environment::forChild($environment); // Windows drops '' values; AIS_ENV_BLANK keeps them blank
        $process = proc_open([$apache, '-X', '-d', $temporary, '-f', $mode . '.conf'], [
            0 => ['pipe', 'r'],
            1 => ['file', $stdout, 'a'],
            2 => ['file', $stderr, 'a'],
        ], $pipes, $project, $environment, ['bypass_shell' => true, 'create_new_console' => false]);
        apacheCheck(is_resource($process), 'Unable to launch private Apache.');
        fclose($pipes[0]);
        $deadline = microtime(true) + 10;
        $ready = false;
        do {
            $connection = @stream_socket_client("tcp://127.0.0.1:{$port}", $errorCode, $errorMessage, 0.1);
            if ($connection !== false) {
                fclose($connection);
                $ready = true;
                break;
            }
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        apacheCheck($ready, 'Private Apache did not start: ' . (@file_get_contents($stderr) ?: '') . (@file_get_contents($log) ?: ''));

        [$status, $headers, $content] = apacheRequest($port, '/');
        apacheCheck($status === 200, "{$mode}: root status {$status}; {$content}");
        apacheCheck(json_decode($content, true, 512, JSON_THROW_ON_ERROR)['success'] === true, "{$mode}: root envelope");
        apacheCheck((bool) preg_grep('/^Content-Type: application\/json/i', $headers), "{$mode}: JSON header");
        [$status, $headers] = apacheRequest($port, '/', 'POST', '{}');
        apacheCheck($status === 405 && (bool) preg_grep('/^Allow: GET$/i', $headers), "{$mode}: 405/Allow");
        foreach (['/missing', '/AGENTS.md', '/.git/config', '/.env', '/app/bootstrap.php', '/tests/run.php'] as $path) {
            [$status, , $content] = apacheRequest($port, $path);
            apacheCheck($status === 404, "{$mode}: {$path} must be 404, got {$status}");
            apacheCheck(json_decode($content, true, 512, JSON_THROW_ON_ERROR)['success'] === false, "{$mode}: private path must use API envelope");
        }
        // L4: private files must not be served. Non-404 is tolerated only if the body leaks no file content.
        $probes = ['/.htaccess' => '.htaccess', '/public/.htaccess' => 'public/.htaccess', '/database/database.sql' => 'database/database.sql', '/config/' => null, '/runtime/' => null];
        if ($mode === 'repository') {
            $probes['/public/router.php'] = 'public/router.php';
        }
        foreach ($probes as $path => $relative) {
            [$status, , $content] = apacheRequest($port, $path);
            if (str_ends_with($path, '.htaccess')) {
                apacheCheck(in_array($status, [403, 404], true), "{$mode}: {$path} must be 403/404, got {$status}");
            }
            if ($status === 200) {
                $real = $relative === null ? false : @file_get_contents($project . '/' . $relative);
                if ($real !== false) {
                    // Distinctive substring: the longest trimmed line of the real file.
                    $lines = array_map('trim', preg_split('/\R/', $real));
                    usort($lines, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
                    apacheCheck(($lines[0] ?? '') === '' || !str_contains($content, $lines[0]), "{$mode}: {$path} leaked file contents");
                }
                apacheCheck(is_array(json_decode($content, true)), "{$mode}: {$path} returned 200 with a non-API body");
            }
        }
        foreach ([[415, 'not-json', 'text/plain'], [400, '{', 'application/json'], [413, str_repeat('x', 65537), 'application/json']] as [$expected, $body, $type]) {
            [$status] = apacheRequest($port, '/', 'POST', $body, $type);
            apacheCheck($status === $expected, "{$mode}: expected {$expected}, got {$status}");
        }
        proc_terminate($process);
        proc_close($process);
        $process = null;
        echo "PASS XAMPP Apache {$mode} document root: 200, 404, 405/Allow, 415, 400, 413, private-file protection\n";
    }

    // The probe reuses the last mock run's private config as a template (same modules and PHP).
    [$baseConfiguration, $basePort, $baseName, $baseRoot] = [$configuration, $port, $mode, $documentRoot];
    $mysql = apacheMysqlProbeEnvironment();
    foreach ($mysql === null ? [] : ['public' => $project . '/public', 'repository' => $project] as $mode => $documentRoot) {
        foreach (['mysql', 'mysql-bad-password'] as $variant) {
            // Fresh, isolated runtime per run (owner decision 5): no session or file state is reused.
            $probeRuntime = str_replace('\\', '/', sys_get_temp_dir()) . '/ais-apache-mysql-' . bin2hex(random_bytes(10));
            apacheCheck(mkdir($probeRuntime, 0700), 'Unable to create probe runtime.');
            try {
                $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
                apacheCheck($socket !== false, 'Unable to reserve a loopback port.');
                $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
                fclose($socket);
                $name = $mode . '-' . $variant;
                $private = str_replace('\\', '/', $temporary);
                $root = str_replace('\\', '/', $documentRoot);
                $config = $temporary . '/' . $name . '.conf';
                $log = $temporary . '/' . $name . '.log';
                $stdout = $temporary . '/' . $name . '-stdout.log';
                $stderr = $temporary . '/' . $name . '-stderr.log';
                array_push($createdFiles, $config, $log, $stdout, $stderr, $temporary . '/' . $name . '.pid');
                apacheCheck(file_put_contents($config, str_replace(
                    ["Listen 127.0.0.1:{$basePort}", "{$private}/{$baseName}.pid", "{$private}/{$baseName}.log", "DocumentRoot \"{$baseRoot}\"", "<Directory \"{$baseRoot}\">"],
                    ["Listen 127.0.0.1:{$port}", "{$private}/{$name}.pid", "{$private}/{$name}.log", "DocumentRoot \"{$root}\"", "<Directory \"{$root}\">"],
                    $baseConfiguration)) !== false, 'Unable to write probe Apache config.');
                $environment = array_replace(getenv(), ['AIS_MYSQL_TEST_APP_PASSWORD' => '', 'DB_MIGRATION_USERNAME' => '', 'DB_MIGRATION_PASSWORD' => ''], $mysql, [
                    'APP_ENV' => 'testing', 'APP_REPOSITORY' => 'mock', 'APP_REPOSITORY_AUTH' => 'mysql',
                    'APP_REPOSITORY_STUDENTS' => 'mock', 'APP_REPOSITORY_ENROLLMENT' => 'mock', 'APP_REPOSITORY_FACULTY' => 'mock',
                    'APP_RUNTIME_PATH' => $probeRuntime,
                ]);
                if ($variant === 'mysql-bad-password') {
                    $environment['DB_PASSWORD'] = 'probe-wrong-password-' . bin2hex(random_bytes(6));
                }
                $environment = App\Core\Environment::forChild($environment); // Windows drops '' values; AIS_ENV_BLANK keeps them blank
                $process = proc_open([$apache, '-X', '-d', $temporary, '-f', $name . '.conf'],
                    [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'a'], 2 => ['file', $stderr, 'a']],
                    $pipes, $project, $environment, ['bypass_shell' => true, 'create_new_console' => false]);
                apacheCheck(is_resource($process), 'Unable to launch probe Apache.');
                fclose($pipes[0]);
                $deadline = microtime(true) + 10;
                $ready = false;
                do {
                    $connection = @stream_socket_client("tcp://127.0.0.1:{$port}", $errorCode, $errorMessage, 0.1);
                    if ($connection !== false) { fclose($connection); $ready = true; break; }
                    if (!proc_get_status($process)['running']) { break; }
                    usleep(50000);
                } while (microtime(true) < $deadline);
                apacheCheck($ready, 'Probe Apache did not start.');
                $jar = [];
                if ($variant === 'mysql') {
                    [$status, $content] = apacheProbeRequest($port, 'GET', '/', null, $jar);
                    apacheCheck($status === 200, "{$name}: root with auth on MySQL must be 200, got {$status}");
                    [$status, $content] = apacheProbeRequest($port, 'GET', '/api/auth/csrf', null, $jar);
                    $token = json_decode($content, true)['data']['csrf_token'] ?? null;
                    apacheCheck($status === 200 && is_string($token), "{$name}: csrf");
                    // An unknown email forces a real users lookup through MySQLUserRepository under mod_php.
                    [$status, $content] = apacheProbeRequest($port, 'POST', '/api/auth/login',
                        ['email' => 'apache-probe-unknown@example.test', 'password' => 'not-a-real-password-123'], $jar, ['X-CSRF-Token: ' . $token]);
                    apacheCheck($status === 401, "{$name}: unknown-email login over MySQL must be 401, got {$status}");
                    echo "PASS XAMPP Apache {$mode} document root with auth on MySQL (ais_test as ais_test_app): 200, CSRF, DB-backed login lookup 401\n";
                } else {
                    [$status, $content] = apacheProbeRequest($port, 'GET', '/', null, $jar);
                    apacheCheck($status === 500 && (json_decode($content, true)['message'] ?? '') === 'An internal error occurred.',
                        "{$name}: a failed DB connection must be the generic 500, got {$status}");
                    foreach (['ais_test', 'ais_test_app', '127.0.0.1', 'SQLSTATE', 'PDO', $environment['DB_PASSWORD']] as $leak) {
                        apacheCheck(!str_contains($content, $leak), "{$name}: outage response leaked connection details");
                    }
                    echo "PASS XAMPP Apache {$mode} document root with MySQL unreachable (wrong password): generic 500, no leak, no fallback\n";
                }
                proc_terminate($process);
                proc_close($process);
                $process = null;
            } finally {
                if (is_resource($process)) { proc_terminate($process); proc_close($process); $process = null; }
                // Remove only this run's own fresh runtime directory.
                if (str_starts_with($probeRuntime, str_replace('\\', '/', sys_get_temp_dir()) . '/ais-apache-mysql-') && is_dir($probeRuntime)) {
                    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($probeRuntime, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST);
                    foreach ($items as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
                    rmdir($probeRuntime);
                }
            }
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL Apache check: ' . $exception->getMessage() . "\n");
    $exit = 1;
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    // Delete only this invocation's explicitly enumerated files; no recursive deletion.
    foreach ($createdFiles as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($temporary);
}
exit($exit);
