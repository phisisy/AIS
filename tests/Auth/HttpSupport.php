<?php
declare(strict_types=1);

use App\Auth\Repositories\MockUserRepository;
use App\Auth\Repositories\MySQLUserRepository;
use App\Auth\Repositories\UserAdminRepositoryInterface;
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
    private readonly ?string $mysqlMode;
    private array $serverEnvironment = [];
    private string $address = '';
    private string $router;

    /** $router and $rows are optional so T-01 callers are unchanged (T-02 uses its own probe router and users). */
    public function __construct(array $overrides = [], string $router = 'public/router.php', ?array $rows = null)
    {
        $this->directory = tempDirectory();
        $requested = getenv('AIS_HTTP_MYSQL');
        $this->mysqlMode = is_string($requested) && $requested !== '' ? $requested : null;
        try {
            if ($this->mysqlMode !== null) {
                check(in_array($this->mysqlMode, ['C1', 'C2', 'C3', 'C4'], true), 'Unknown HTTP MySQL combination.');
                require_once dirname(__DIR__) . '/Database/MySQLTestSupport.php';
                check(is_string(getenv('AIS_MYSQL_TEST_APP_PASSWORD')) && MySQLTestSupport::enabled(),
                    'HTTP MySQL switch requires the guarded online test target and app password.');
            }
            (new MockUserRepository(new JsonStore($this->directory . '/auth/users.json')))->seed($rows ?? authTestRows());
            if ($this->mysqlMode !== null) {
                check(array_values(array_diff(scandir($this->directory), ['.', '..'])) === ['auth'],
                    'HTTP test runtime was reused.');
            }
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            check(is_resource($socket), 'Cannot reserve auth test port.');
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $this->url = 'http://' . $address;
            $this->address = $address;
            $this->router = $router;
            $this->serverEnvironment = array_merge(getenv(), [
                'APP_ENV' => 'testing',
                'APP_REPOSITORY' => 'mock',
                'APP_RUNTIME_PATH' => $this->directory,
                'APP_GRADE_SCALE' => 'off', // pinned: a shell/.env value must not flip default HTTP tests into demo mode
            ], $overrides);
            if ($this->mysqlMode !== null) {
                // The switch owns these values; caller overrides cannot redirect the online target.
                $this->serverEnvironment = array_replace($this->serverEnvironment, [
                    'APP_ENV' => 'testing', 'APP_REPOSITORY' => 'mock',
                    'APP_RUNTIME_PATH' => $this->directory,
                    'DB_DATABASE' => 'ais_test', 'DB_USERNAME' => 'ais_test_app',
                    'DB_PASSWORD' => (string) getenv('AIS_MYSQL_TEST_APP_PASSWORD'),
                    'APP_REPOSITORY_AUTH' => 'mysql',
                    // Config rules: enrollment=mysql needs students and faculty =mysql (T-22/T-30); grades=mysql needs
                    // students and enrollment =mysql (T-32). So C3 = students+enrollment+faculty, C4 = C3 + grades.
                    'APP_REPOSITORY_STUDENTS' => in_array($this->mysqlMode, ['C2', 'C3', 'C4'], true) ? 'mysql' : 'mock',
                    'APP_REPOSITORY_ENROLLMENT' => in_array($this->mysqlMode, ['C3', 'C4'], true) ? 'mysql' : 'mock',
                    'APP_REPOSITORY_FACULTY' => in_array($this->mysqlMode, ['C3', 'C4'], true) ? 'mysql' : 'mock',
                    'APP_REPOSITORY_GRADES' => $this->mysqlMode === 'C4' ? 'mysql' : 'mock',
                    'DB_HOST' => (string) getenv('DB_HOST'), 'DB_PORT' => (string) getenv('DB_PORT'),
                ]);
                // Tester secrets must not reach the child; DB_PASSWORD above is already the app password.
                $this->serverEnvironment = array_diff_key($this->serverEnvironment, array_flip(
                    ['DB_MIGRATION_USERNAME', 'DB_MIGRATION_PASSWORD', 'AIS_MYSQL_TEST_APP_PASSWORD']));
                return; // Other HTTP suites finish seeding before their first request.
            }
            $this->start();
        } catch (Throwable $exception) {
            $this->close();
            throw $exception;
        }
    }

    private function start(): void
    {
        $this->process = proc_open(
                [PHP_BINARY, '-d', 'display_errors=0', '-d', 'display_startup_errors=0', '-d', 'upload_tmp_dir=' . $this->directory, '-S', $this->address, $this->router],
                [0 => ['pipe', 'r'], 1 => ['file', $this->directory . '/stdout.log', 'w'], 2 => ['file', $this->directory . '/stderr.log', 'w']],
                $pipes,
                dirname(__DIR__, 2),
                $this->serverEnvironment,
                ['bypass_shell' => true]
            );
            check(is_resource($this->process), 'Cannot launch auth test server.');
            fclose($pipes[0]);
            $deadline = microtime(true) + 5;
            do {
                $connection = @stream_socket_client('tcp://' . $this->address, $errno, $error, 0.1);
                if (is_resource($connection)) {
                    fclose($connection);
                    return;
                }
                check(proc_get_status($this->process)['running'], 'Auth test server exited.');
                usleep(20000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('Auth test server did not become ready.');
    }

    private function prepareMysql(): void
    {
        check(!is_dir($this->directory . '/sessions') && !is_file($this->directory . '/auth/login-attempts.json')
            && !is_file($this->directory . '/auth/security-events.json'), 'HTTP MySQL runtime was reused.');
        require_once dirname(__DIR__, 2) . '/database/tools/MigrationRunner.php';
        $source = tempDirectory();
        try {
            $modules = ['auth'];
            // Faculty rows are seeded by the tester directly here: the importer's faculty module needs a faculty JSON
            // store, which these HTTP fixtures don't create. Grades import is refused (scale unconfigured; tables start empty).
            if (in_array($this->mysqlMode, ['C2', 'C3', 'C4'], true)) { $modules[] = 'students'; }
            if (in_array($this->mysqlMode, ['C3', 'C4'], true)) { $modules[] = 'enrollment'; }
            $files = ['auth' => ['auth/users.json'], 'students' => ['students/programs.json', 'students/students.json'],
                'enrollment' => ['enrollment/enrollment.json']];
            foreach ($modules as $module) {
                foreach ($files[$module] as $relative) {
                    $from = $this->directory . '/' . $relative;
                    $to = $source . '/' . $relative;
                    if (!is_dir(dirname($to))) { mkdir(dirname($to), 0700, true); }
                    file_put_contents($to, is_file($from) ? file_get_contents($from) : '{}');
                    file_put_contents($to . '.lock', '');
                }
            }
            $owner = MySQLTestSupport::connect();
            check(str_starts_with((string) $owner->query('SELECT CURRENT_USER()')->fetchColumn(), 'ais_tester@'),
                'HTTP MySQL reset requires the tester account.');
            MySQLTestSupport::reset($owner);
            (new \Database\Tools\MigrationRunner($owner, 'ais_test', dirname(__DIR__, 2) . '/database/migrations'))->up();
            $environment = array_replace(getenv(), [
                'APP_ENV' => 'testing', 'APP_RUNTIME_PATH' => $source,
                'DB_DATABASE' => 'ais_test',
                'DB_MIGRATION_USERNAME' => (string) getenv('DB_USERNAME'),
                'DB_MIGRATION_PASSWORD' => (string) getenv('DB_PASSWORD'),
            ]);
            foreach ($modules as $module) {
                [$exit, , $stderr] = runProcess([PHP_BINARY, dirname(__DIR__, 2) . '/database/import_json.php',
                    '--module=' . $module, '--runtime=' . $source, '--target=ais_test'], $environment);
                check($exit === 0, 'HTTP MySQL seed import failed for ' . $module . ': ' . $stderr);
                foreach ($files[$module] as $relative) {
                    $path = $this->directory . '/' . $relative;
                    if (is_file($path)) { unlink($path); }
                    if (is_file($path . '.lock')) { unlink($path . '.lock'); }
                }
            }
        } finally {
            removeTestDirectory($source);
        }
    }

    public function request(string $method, string $path, ?array $payload = null, ?string $csrf = null, array $extraHeaders = []): array
    {
        if ($this->mysqlMode !== null && !is_resource($this->process)) {
            $this->prepareMysql();
            $this->start();
        }
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

    /**
     * Fixture sign-in. Role matrices sign the same synthetic account in more than five times per server,
     * which the real limiter (5 per IP+email, 30 per IP, 15 min) correctly refuses with 429. So this helper
     * clears ONLY this test server's private attempt store, under its own lock, before each fixture sign-in.
     * The limiter itself is asserted through raw request() calls in AuthHttpTest and SessionAndLimiterTest,
     * which never pass through this helper.
     */
    public function login(string $email = 'student@example.test'): array
    {
        $attempts = $this->directory . '/auth/login-attempts.json';
        if (is_file($attempts)) { // absent before the first request (the MySQL switch requires a fresh runtime)
            (new JsonStore($attempts))->transaction(static function (array &$data): void { $data = ['ip' => [], 'pair' => []]; });
        }
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

// ---------------------------------------------------------------------------------------------------------------------
// Backend-agnostic state helpers. In mock mode (no AIS_HTTP_MYSQL) they read and edit the JSON stores exactly as the tests
// did before. On the HTTP MySQL switch (C1-C4) the JSON stores are deleted after the seed import and the server reads SQL, so
// the SAME facts are read, and the SAME logical state changes are made, with the guarded tester connection. Call them only
// after the server's first request (the switch resets and imports lazily on it).
// ---------------------------------------------------------------------------------------------------------------------

/** The combination requested by the HTTP MySQL switch (C1-C4), or null in mock mode. */
function httpMysqlMode(): ?string
{
    $mode = getenv('AIS_HTTP_MYSQL');
    return is_string($mode) && $mode !== '' ? $mode : null;
}

/** Students are on SQL in C2-C4 (their JSON stores are gone after the import). */
function httpStudentsOnMysql(): bool
{
    return in_array(httpMysqlMode(), ['C2', 'C3', 'C4'], true);
}

/** Guarded tester connection (ais_tester on ais_test only). */
function httpTesterPdo(): PDO
{
    check(httpMysqlMode() !== null, 'Tester access is only for the HTTP MySQL switch.');
    require_once dirname(__DIR__) . '/Database/MySQLTestSupport.php';
    $pdo = MySQLTestSupport::connect();
    check(str_starts_with((string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn(), 'ais_tester@'), 'Tester account required.');
    return $pdo;
}

/** The auth store the server really uses, for reads (findById, auditEntries) and for repository transactions. */
function serverUsers(AuthHttpServer $server): UserAdminRepositoryInterface
{
    return httpMysqlMode() === null
        ? new MockUserRepository(new JsonStore($server->directory . '/auth/users.json'))
        : new MySQLUserRepository(httpTesterPdo());
}

/** Mock mode only: raw edit of the users array ($edit receives the list by reference and the index of user $id). */
function httpEditMockUsers(AuthHttpServer $server, int $id, callable $edit): void
{
    (new JsonStore($server->directory . '/auth/users.json'))->transaction(static function (array &$data) use ($id, $edit): void {
        $index = array_search($id, array_column($data['users'], 'id'), true);
        check($index !== false, 'Missing user ' . $id);
        $edit($data['users'], $index);
    });
}

/** Raw status change with no auth_version bump (the store edit the tests always used). */
function serverSetUserStatus(AuthHttpServer $server, int $id, string $status): void
{
    if (httpMysqlMode() === null) {
        httpEditMockUsers($server, $id, static function (array &$users, int $i) use ($status): void { $users[$i]['status'] = $status; });
        return;
    }
    httpTesterPdo()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $id]);
}

/** Raw auth_version increment with no other change. */
function serverBumpAuthVersion(AuthHttpServer $server, int $id): void
{
    if (httpMysqlMode() === null) {
        httpEditMockUsers($server, $id, static function (array &$users, int $i): void { $users[$i]['auth_version']++; });
        return;
    }
    httpTesterPdo()->prepare('UPDATE users SET auth_version = auth_version + 1 WHERE id = ?')->execute([$id]);
}

/** Raw role replacement with no auth_version bump. */
function serverSetUserRoles(AuthHttpServer $server, int $id, array $roles): void
{
    if (httpMysqlMode() === null) {
        httpEditMockUsers($server, $id, static function (array &$users, int $i) use ($roles): void { $users[$i]['roles'] = $roles; });
        return;
    }
    $pdo = httpTesterPdo();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);
        foreach ($roles as $role) {
            $pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (?, ?)')->execute([$id, $role]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

/**
 * Hard delete of a user nothing else references (the tester owns the schema; the app account cannot delete). The auth HTTP
 * suites reference user $id from no student, enrollment or audit row, so the RESTRICT foreign keys allow it.
 */
function serverDeleteUser(AuthHttpServer $server, int $id): void
{
    if (httpMysqlMode() === null) {
        httpEditMockUsers($server, $id, static function (array &$users, int $i): void { array_splice($users, $i, 1); });
        return;
    }
    $pdo = httpTesterPdo();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

/**
 * Stored roles the application must refuse. Mock: the unknown role 'superuser', as before. MySQL: the roles CHECK constraint
 * refuses an unknown role outright (asserted here), so the equivalent model-invalid row is a user with no role rows at all
 * (the schema allows it, User::fromArray refuses it), as the V9 tests inject a row the model rejects.
 */
function serverCorruptUserRoles(AuthHttpServer $server, int $id): void
{
    if (httpMysqlMode() === null) {
        serverSetUserRoles($server, $id, ['superuser']);
        return;
    }
    $pdo = httpTesterPdo();
    $refused = false;
    try {
        $pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (?, ?)')->execute([$id, 'superuser']);
    } catch (PDOException) {
        $refused = true;
    }
    check($refused, 'The roles CHECK constraint must refuse an unknown role.');
    $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);
}

/**
 * Order-independent row-level hash of the given tables (plus one repository_locks row), the MySQL equivalent of comparing
 * store bytes before and after. The tables must exist (call before moving them away and after restoring them).
 */
function httpTableSnapshot(array $tables, ?string $lockName = null): string
{
    $pdo = httpTesterPdo();
    $parts = [];
    foreach ($tables as $table) {
        check(preg_match('/\A[a-z_]+\z/', $table) === 1, 'Bad table name.');
        $rows = array_map('serialize', $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC));
        sort($rows);
        $parts[] = $table . ':' . implode("\n", $rows);
    }
    if ($lockName !== null) {
        $statement = $pdo->prepare('SELECT name, last_seq FROM repository_locks WHERE name = ?');
        $statement->execute([$lockName]);
        $parts[] = 'lock:' . serialize($statement->fetchAll(PDO::FETCH_ASSOC));
    }
    return hash('sha256', implode("\n--\n", $parts));
}

/**
 * MySQL stand-in for an unreadable store file: moves the tables away so every query on them fails (the app turns that into its
 * generic 500), or restores them. Always restore in a finally block; the next switch run also resets the schema.
 */
function httpMoveTables(array $tables, bool $away): void
{
    $pdo = httpTesterPdo();
    $pdo->exec('SET SESSION foreign_key_checks = 0');
    $done = [];
    try {
        foreach ($tables as $table) {
            check(preg_match('/\A[a-z_]+\z/', $table) === 1, 'Bad table name.');
            $pdo->exec($away ? "RENAME TABLE `{$table}` TO `{$table}__moved`" : "RENAME TABLE `{$table}__moved` TO `{$table}`");
            $done[] = $table;
        }
    } catch (Throwable $error) {
        // Undo the renames that succeeded (reverse order), then rethrow the original failure.
        foreach (array_reverse($done) as $table) {
            try {
                $pdo->exec($away ? "RENAME TABLE `{$table}__moved` TO `{$table}`" : "RENAME TABLE `{$table}` TO `{$table}__moved`");
            } catch (Throwable) {
                // keep rolling back the rest; the next switch run resets the schema anyway
            }
        }
        throw $error;
    } finally {
        $pdo->exec('SET SESSION foreign_key_checks = 1');
    }
}
