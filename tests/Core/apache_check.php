<?php
declare(strict_types=1);

// Optional XAMPP acceptance check, separate from the portable offline suite.
// Uses only a private config and a temporary loopback listener, never a service.
$project = dirname(__DIR__, 2);
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
        $environment = getenv();
        $environment['APP_REPOSITORY'] = 'mock';
        $environment['APP_ENV'] = 'testing';
        $environment['APP_RUNTIME_PATH'] = $temporary;
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
        foreach ([[415, 'not-json', 'text/plain'], [400, '{', 'application/json'], [413, str_repeat('x', 65537), 'application/json']] as [$expected, $body, $type]) {
            [$status] = apacheRequest($port, '/', 'POST', $body, $type);
            apacheCheck($status === $expected, "{$mode}: expected {$expected}, got {$status}");
        }
        proc_terminate($process);
        proc_close($process);
        $process = null;
        echo "PASS XAMPP Apache {$mode} document root: 200, 404, 405/Allow, 415, 400, 413, private-file protection\n";
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
