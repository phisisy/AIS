<?php
declare(strict_types=1);

// DB-31 demo data orchestrator. UNVERIFIED: written from source only, never run.
// Trusted machine only. Follows docs/database/DEMO_DATA_SPEC.md sections 3.2-3.5.
// Usage: php database/demo/generate_demo_data.php --runtime=<abs new empty dir> --confirm-ais-test

use App\Core\Environment;

if (PHP_SAPI !== 'cli') { http_response_code(403); exit(2); }

$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';

const DEMO_SECRET_KEYS = ['DB_MIGRATION_USERNAME', 'DB_MIGRATION_PASSWORD', 'AIS_MYSQL_TEST_APP_PASSWORD', 'DB_PASSWORD'];

final class DemoRefused extends RuntimeException {}
final class DemoFailed extends RuntimeException {}

/** Guards run before any process, directory or file is created. */
function demoGuards(array $argv, string $root): array
{
    try { Environment::load($root . '/.env'); } catch (Throwable) { throw new DemoRefused('x'); }
    $env = static fn (string $k): string => (string) Environment::get($k, '');
    $port = $env('DB_PORT');
    // N1: passwords come from the process environment only; children reload .env, so .env must not hold them.
    $testerPw = (string) getenv('DB_MIGRATION_PASSWORD');
    $appPw = (string) getenv('AIS_MYSQL_TEST_APP_PASSWORD');
    $envFile = is_file($root . '/.env') ? file($root . '/.env', FILE_IGNORE_NEW_LINES) : [];
    foreach ($envFile ?: [] as $line) {
        if (preg_match('/\A\s*(DB_MIGRATION_PASSWORD|AIS_MYSQL_TEST_APP_PASSWORD)\s*=/', $line)) { throw new DemoRefused('envfile'); }
    }
    if ($env('APP_ENV') !== 'testing' || $env('DB_DATABASE') !== 'ais_test' || $env('DB_HOST') !== '127.0.0.1'
        || $env('DB_MIGRATION_USERNAME') !== 'ais_tester' || $testerPw === ''
        || $appPw === '' || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new DemoRefused('env');
    }
    $runtime = null; $confirmed = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--confirm-ais-test') { $confirmed = true; }
        elseif (str_starts_with($arg, '--runtime=') && $runtime === null) { $runtime = substr($arg, 10); }
        else { throw new DemoRefused('args'); }
    }
    if (!$confirmed || $runtime === null || $runtime === '') { throw new DemoRefused('args'); }
    $norm = str_replace('\\', '/', $runtime);
    if (!preg_match('~^([A-Za-z]:/|/)~', $norm) || str_contains($norm, "\0") || str_contains($norm, '..')
        || str_contains($norm, '//') || stripos($norm, 'onedrive') !== false || preg_match('~^[a-z]+://~i', $norm)) {
        throw new DemoRefused('runtime');
    }
    $norm = rtrim($norm, '/');
    // L2: resolve the real location (existing dir, else realpath of the parent + basename) so a symlinked parent cannot bypass the checks.
    $real = is_dir($norm) ? realpath($norm) : realpath(dirname($norm));
    $resolved = $real === false ? '' : str_replace('\\', '/', rtrim($real, '\\/')) . (is_dir($norm) ? '' : '/' . basename($norm));
    $repo = str_replace('\\', '/', rtrim((string) realpath($root), '\\/'));
    if ($resolved === '' || $repo === '' || strcasecmp($resolved, $repo) === 0 || stripos($resolved . '/', $repo . '/') === 0) {
        throw new DemoRefused('runtime'); // at or under the repository root (includes public/)
    }
    if (stripos($resolved, 'onedrive') !== false) { throw new DemoRefused('runtime'); }
    if (is_dir($norm)) {
        $items = scandir($norm);
        if ($items === false || count($items) > 2) { throw new DemoRefused('runtime'); }
    } elseif (file_exists($norm) || !is_dir(dirname($norm))) {
        throw new DemoRefused('runtime');
    }
    return ['runtime' => $norm, 'port' => $port, 'tester_password' => $testerPw,
        'app_password' => $appPw];
}

try {
    $cfg = demoGuards($argv, $root);
} catch (DemoRefused) {
    fwrite(STDERR, "Refused: guard conditions not met.\n");
    exit(2);
}
if (!defined('DEMO_GUARDS_ONLY') || DEMO_GUARDS_ONLY !== true) { demoMain($cfg, $root); }

/** Run a command; stdout is returned, never echoed. */
function demoRun(array $cmd, string $cwd, array $envOverride, array $envRemove = []): array
{
    // N1: secret keys are set to '' (an empty process value beats .env in Environment::get); other keys are unset.
    $blank = array_intersect($envRemove, DEMO_SECRET_KEYS);
    $env = array_diff_key(getenv(), array_flip($envRemove));
    $env = Environment::forChild(array_replace($env, array_fill_keys($blank, ''), $envOverride)); // Windows drops '' values; AIS_ENV_BLANK keeps them blank
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    if (!is_resource($p)) { throw new DemoFailed('process start'); }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]); $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $out, $err];
}

/** One HTTP actor: cookie jar plus CSRF token, in memory only. */
final class DemoActor
{
    private array $cookies = [];
    private string $csrf = '';
    public function __construct(private string $base) {}

    public function call(string $method, string $path, ?array $body, int $expect, string $step, ?string $contains = null): array
    {
        $headers = ['Accept: application/json'];
        if ($this->cookies) { $headers[] = 'Cookie: ' . implode('; ', array_map(
            static fn ($k, $v) => $k . '=' . $v, array_keys($this->cookies), $this->cookies)); }
        if ($method !== 'GET') { $headers[] = 'X-CSRF-Token: ' . $this->csrf; }
        $http = ['method' => $method, 'ignore_errors' => true, 'timeout' => 30];
        if ($body !== null) { $headers[] = 'Content-Type: application/json'; $http['content'] = json_encode($body, JSON_THROW_ON_ERROR); }
        $http['header'] = implode("\r\n", $headers);
        $raw = @file_get_contents($this->base . $path, false, stream_context_create(['http' => $http]));
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) { $status = (int) $m[1]; }
            if (stripos($line, 'Set-Cookie:') === 0 && preg_match('~^Set-Cookie:\s*([^=;\s]+)=([^;]*)~i', $line, $m)) {
                $this->cookies[$m[1]] = $m[2];
            }
        }
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if ($status !== $expect || ($contains !== null && !str_contains((string) $raw, $contains))) {
            throw new DemoFailed("{$step}: expected {$expect}" . ($contains ? " containing marker" : '') . ", got {$status}");
        }
        echo "OK {$status} {$step}\n";
        return is_array($json) ? $json : [];
    }

    public function login(string $email, string $password, int $expect = 200, string $step = 'login'): void
    {
        $this->cookies = []; $this->csrf = '';
        $j = $this->call('GET', '/api/auth/csrf', null, 200, $step . ' csrf');
        $this->csrf = (string) ($j['data']['csrf_token'] ?? '');
        $j = $this->call('POST', '/api/auth/login', ['email' => $email, 'password' => $password], $expect, $step);
        if ($expect === 200) { $this->csrf = (string) ($j['data']['csrf_token'] ?? $this->csrf); }
    }
}

function demoId(array $json, string ...$hints): int
{
    $d = $json['data'] ?? [];
    foreach ($hints as $h) { if (isset($d[$h]['id'])) { return (int) $d[$h]['id']; } }
    if (isset($d['id'])) { return (int) $d['id']; }
    throw new DemoFailed('response id missing');
}

function demoRows(array $json): array
{
    $d = $json['data'] ?? [];
    if (array_is_list($d)) { return $d; }
    foreach ($d as $v) { if (is_array($v) && array_is_list($v)) { return $v; } }
    return [];
}

function demoFind(array $rows, string $key, mixed $value, string $step): array
{
    foreach ($rows as $r) { if (is_array($r) && ($r[$key] ?? null) === $value) { return $r; } }
    throw new DemoFailed($step . ': row not found');
}

function demoStartServer(string $root, string $runtime, string $appPassword, string $port): array
{
    $http = $runtime . '/http-sessions';
    if (!mkdir($http, 0700, true)) { throw new DemoFailed('http runtime'); } // umask(0077) was set in demoMain
    $env = array_replace(getenv(), ['APP_ENV' => 'testing', 'APP_REPOSITORY' => 'mysql', 'DB_DATABASE' => 'ais_test',
        'DB_HOST' => '127.0.0.1', 'DB_USERNAME' => 'ais_test_app', 'DB_PASSWORD' => $appPassword, 'APP_RUNTIME_PATH' => $http,
        'APP_GRADE_SCALE' => 'demo']); // demo-only scale, not the official university policy
    // M1: the web server must never hold the tester or test-app credentials beyond its pinned DB_USERNAME/DB_PASSWORD.
    unset($env['STUDENT_NUMBER_PATTERN'], $env['FACULTY_EMPLOYEE_NUMBER_PATTERN']);
    // N1: blank (not unset) so the child's .env reload cannot restore them.
    $env = Environment::forChild(array_replace($env, ['DB_MIGRATION_USERNAME' => '', 'DB_MIGRATION_PASSWORD' => '', 'AIS_MYSQL_TEST_APP_PASSWORD' => '']));
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $srv = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', 'public', 'public/router.php'],
        [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, $root, $env);
    if (!is_resource($srv)) { throw new DemoFailed('server start'); }
    for ($i = 0; $i < 40; $i++) {
        usleep(250000);
        $c = @fsockopen('127.0.0.1', (int) $port, $e1, $e2, 1);
        if ($c) { fclose($c); return [$srv, 'http://127.0.0.1:' . $port]; }
    }
    proc_terminate($srv);
    throw new DemoFailed('server did not start');
}

function demoMain(array $cfg, string $root): void
{
    $runtime = $cfg['runtime']; $server = null; $status = 0;
    try {
        umask(0077); // before any mkdir/file creation
        if (!is_dir($runtime) && !mkdir($runtime, 0700)) { throw new DemoFailed('runtime create'); }
        @chmod($runtime, 0700); // existing empty dir: tighten where supported (Windows ignores mode bits)
        $pwFile = $runtime . '/demo-passwords.txt';
        $pwHandle = fopen($pwFile, 'xb'); // exclusive create; never reuse an existing file
        if ($pwHandle === false) { throw new DemoFailed('password file create'); }
        fclose($pwHandle);
        $php = PHP_BINARY;
        $seedEnv = ['APP_ENV' => 'development', 'APP_REPOSITORY' => 'mock', 'APP_RUNTIME_PATH' => $runtime];
        // M1/N1: blanked for seeders (incl. DB_PASSWORD) and verify (which overrides DB_PASSWORD itself).
        $secrets = DEMO_SECRET_KEYS;
        $strip = array_merge(['STUDENT_NUMBER_PATTERN', 'FACULTY_EMPLOYEE_NUMBER_PATTERN'], $secrets);

        // Phase A: seed (spec 3.2). Passwords go to the file only.
        $passwords = [];
        foreach (['dev_users', 'dev_students', 'dev_sections'] as $seeder) {
            [$code, $out] = demoRun([$php, "database/seeders/{$seeder}.php"], $root, $seedEnv, $strip);
            if ($code !== 0) { throw new DemoFailed("seeder {$seeder} exit {$code}"); }
            if ($seeder === 'dev_users') {
                foreach (preg_split('/\R/', $out) as $line) {
                    if (preg_match('/^(\S+@example\.test)\s+(\S+)\s*$/', $line, $m)) { $passwords[$m[1]] = $m[2]; }
                }
                if (count($passwords) !== 4) { throw new DemoFailed('seeder passwords not captured'); }
            }
            echo "OK seeder {$seeder}\n";
        }
        if (file_exists($runtime . '/faculty/grades.json')) { throw new DemoFailed('faculty/grades.json exists'); }
        $save = static function (array $pw) use ($pwFile): void {
            foreach ($pw as $email => $p) { file_put_contents($pwFile, $email . ' ' . $p . PHP_EOL, FILE_APPEND); }
        };
        $save($passwords);
        echo "Demo passwords are in {$pwFile} (not printed).\n";

        // Phase A: import auth, students, enrollment (spec 3.3). Faculty is imported via the API, not here.
        $impEnv = ['APP_ENV' => 'testing', 'DB_MIGRATION_USERNAME' => 'ais_tester', 'DB_MIGRATION_PASSWORD' => $cfg['tester_password']];
        // M1: imports keep DB_MIGRATION_*, but never see the app password.
        $impStrip = ['STUDENT_NUMBER_PATTERN', 'FACULTY_EMPLOYEE_NUMBER_PATTERN', 'APP_RUNTIME_PATH', 'AIS_MYSQL_TEST_APP_PASSWORD', 'DB_PASSWORD'];
        foreach (['auth', 'students', 'enrollment'] as $module) {
            foreach ([true, false] as $dry) {
                $cmd = [$php, 'database/import_json.php', "--module={$module}", '--runtime=' . $runtime, '--target=ais_test'];
                if ($dry) { $cmd[] = '--dry-run'; }
                [$code, $out] = demoRun($cmd, $root, $impEnv, $impStrip);
                $marker = $dry ? 'DRY RUN ROLLED BACK' : 'IMPORT COMMITTED';
                if ($code !== 0 || !str_contains($out, $marker)) { throw new DemoFailed("import {$module}" . ($dry ? ' dry-run' : '') . " exit {$code}"); }
                echo "OK import {$module}" . ($dry ? ' (dry run)' : '') . "\n";
            }
        }
        $appEnv = ['APP_ENV' => 'testing', 'DB_USERNAME' => 'ais_test_app', 'DB_PASSWORD' => $cfg['app_password']];
        [$code] = demoRun([$php, 'database/verify.php', '--target=ais_test'], $root, $appEnv, $secrets);
        if ($code !== 0) { throw new DemoFailed("verify after import exit {$code}"); }

        // Phase B: MySQL-backed server (spec 3.4).
        $s = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
        if ($s === false) { throw new DemoFailed('free port'); }
        $port = (string) parse_url('tcp://' . stream_socket_get_name($s, false), PHP_URL_PORT);
        fclose($s);
        [$server, $base] = demoStartServer($root, $runtime, $cfg['app_password'], $port);

        $admin = new DemoActor($base); $reg = new DemoActor($base);
        $admin->login('admin@example.test', $passwords['admin@example.test'], 200, 'login admin');
        $reg->login('registrar@example.test', $passwords['registrar@example.test'], 200, 'login registrar');

        // B1: nine accounts.
        $newUsers = [];
        $accounts = [['faculty2', 'faculty'], ['faculty3', 'faculty']];
        for ($n = 2; $n <= 8; $n++) { $accounts[] = ['student' . $n, 'student']; }
        foreach ($accounts as [$local, $role]) {
            $email = $local . '@example.test'; $pw = bin2hex(random_bytes(12));
            $j = $admin->call('POST', '/api/admin/users', ['email' => $email, 'password' => $pw, 'roles' => [$role], 'status' => 'active'], 201, "B1 create {$local}");
            $newUsers[$local] = demoId($j, 'user'); $passwords[$email] = $pw; $save([$email => $pw]);
        }
        $uid = static fn (string $l): int => $newUsers[$l];

        // B2: student profiles.
        $profiles = [['student2', 'DEMO-0002', 'Alma', 'Testa', 'BSCS', 2], ['student3', 'DEMO-0003', 'Bruno', 'Ejemplo', 'BSIT', 1],
            ['student4', 'DEMO-0004', 'Celia', 'Muestra', 'BSIT', 3], ['student5', 'DEMO-0005', 'Dario', 'Prueba', 'BSED', 2],
            ['student6', 'DEMO-0006', 'Elena', 'Ficta', 'BSIT', 2], ['student7', 'DEMO-0007', 'Felix', 'Ensayo', 'BSCS', 4],
            ['student8', 'DEMO-0008', 'Gina', 'Borrador', 'BSED', 1]];
        foreach ($profiles as [$l, $no, $fn, $ln, $prog, $yr]) {
            $reg->call('POST', '/api/students', ['user_id' => $uid($l), 'student_number' => $no, 'first_name' => $fn, 'middle_name' => null,
                'last_name' => $ln, 'program_code' => $prog, 'year_level' => $yr, 'academic_status' => 'active', 'admission_year' => null], 201, "B2 profile {$no}");
        }

        // B3: faculty profiles (user 3 = seeded faculty@; ids from the API).
        $users = demoRows($admin->call('GET', '/api/admin/users?search=faculty@example.test', null, 200, 'B3 find faculty@'));
        $f1User = (int) demoFind($users, 'email', 'faculty@example.test', 'B3 faculty@')['id'];
        $comp = 'Department of Computing (fictional)'; $ge = 'Department of General Education (fictional)';
        $fac = [];
        foreach ([['F1', $f1User, 'EMP-D001', 'Iris', 'Docente', $comp], ['F2', $uid('faculty2'), 'EMP-D002', 'Julio', 'Profesor', $comp],
            ['F3', $uid('faculty3'), 'EMP-D003', 'Karla', 'Catedra', $ge]] as [$k, $u, $no, $fn, $ln, $dep]) {
            $fac[$k] = demoId($reg->call('POST', '/api/registrar/faculty', ['user_id' => $u, 'employee_number' => $no,
                'first_name' => $fn, 'last_name' => $ln, 'department' => $dep], 201, "B3 faculty {$no}"), 'faculty');
        }

        // B4: deactivate student8 after its profile exists.
        $admin->call('PATCH', '/api/admin/users/' . $uid('student8') . '/status', ['status' => 'inactive'], 200, 'B4 deactivate student8');

        // Catalog lookups.
        $terms = demoRows($reg->call('GET', '/api/terms', null, 200, 'lookup terms'));
        $t1 = (int) demoFind($terms, 'code', '2026-1S', 'term 2026-1S')['id'];
        $sec = static function (array $rows, string $course, string $code = 'A') use (&$sec): int {
            foreach ($rows as $r) { if (($r['course_code'] ?? '') === $course && ($r['section_code'] ?? '') === $code) { return (int) $r['id']; } }
            throw new DemoFailed("section {$course} not found");
        };
        $t1Rows = demoRows($reg->call('GET', '/api/sections?term_id=' . $t1, null, 200, 'lookup sections term 1'));
        $cs101a = $sec($t1Rows, 'CS101'); $cs101b = $sec($t1Rows, 'CS101', 'B'); $it211 = $sec($t1Rows, 'IT211');

        $mk = static fn (int $term, string $c, string $title, string $code, int $cap, array $meet, string $mode = 'scheduled'): array =>
            ['term_id' => $term, 'course_code' => $c, 'course_title' => $title, 'units' => 3, 'section_code' => $code, 'capacity' => $cap,
             'meetings' => $meet] + ($mode === 'tba' ? ['delivery_mode' => 'tba'] : []);
        $m = static fn (array $days, string $s, string $e, string $room): array =>
            array_map(static fn ($d) => ['day' => $d, 'start' => $s, 'end' => $e, 'room' => $room], $days);

        // B5: past term, enrollments, close.
        $j = $reg->call('POST', '/api/registrar/terms', ['code' => '2025-2S', 'name' => 'Second Semester 2025 (fictional)',
            'start_date' => '2026-01-12', 'end_date' => '2026-05-29', 'is_current' => false, 'enrollment_open' => true], 201, 'B5a term 2025-2S');
        $t3 = demoId($j, 'term');
        $cs100 = demoId($reg->call('POST', '/api/registrar/sections', $mk($t3, 'CS100', 'Computing Fundamentals', 'A', 30,
            $m(['WED', 'FRI'], '15:00', '16:30', 'R-106')), 201, 'B5b section CS100-A'), 'section');
        $reg->call('POST', "/api/registrar/sections/{$cs100}/instructors", ['faculty_id' => $fac['F1']], 201, 'B5c assign F1 CS100-A');

        $stu = [];
        foreach (['student' => 'student@example.test', 'student2', 'student3', 'student4', 'student5', 'student6', 'student7'] as $k => $v) {
            $local = is_int($k) ? $v : $k; $email = $local . '@example.test';
            $stu[$local] = new DemoActor($base);
            $stu[$local]->login($email, $passwords[$email], 200, "login {$local}");
        }
        $enr = [];
        $enroll = static function (string $who, int $section, string $step) use (&$enr, $stu): void {
            $enr[] = demoId($stu[$who]->call('POST', '/api/enrollments', ['section_id' => $section], 201, $step), 'enrollment');
        };
        $enroll('student', $cs100, 'B5d enrollment 1'); $enroll('student2', $cs100, 'B5d enrollment 2');
        foreach ([0, 1] as $i) { $reg->call('PATCH', '/api/registrar/enrollments/' . $enr[$i], ['status' => 'approved'], 200, 'B5e approve ' . ($i + 1)); }
        $reg->call('PATCH', "/api/registrar/terms/{$t3}", ['enrollment_open' => false], 200, 'B5f close term 2025-2S');

        // B6: sections 6-8.
        $ge105 = demoId($reg->call('POST', '/api/registrar/sections', $mk($t1, 'GE105', 'Readings in General Studies', 'A', 50, [], 'tba'), 201, 'B6 GE105-A'), 'section');
        $it221 = demoId($reg->call('POST', '/api/registrar/sections', $mk($t1, 'IT221', 'Data Structures', 'A', 2, $m(['TUE'], '08:00', '09:30', 'LAB-2')), 201, 'B6 IT221-A'), 'section');
        $cs130 = demoId($reg->call('POST', '/api/registrar/sections', $mk($t1, 'CS130', 'Discrete Structures', 'A', 30, $m(['MON'], '09:00', '10:30', 'R-104')), 201, 'B6 CS130-A'), 'section');

        // B7/B8: instructors, then unassign F3 from CS101-B.
        foreach ([['F1', $cs101a], ['F1', $cs101b], ['F2', $it211], ['F2', $it221], ['F3', $ge105], ['F3', $cs130], ['F3', $cs101b]] as [$f, $s]) {
            $reg->call('POST', "/api/registrar/sections/{$s}/instructors", ['faculty_id' => $fac[$f]], 201, "B7 assign {$f} to section {$s}");
        }
        $reg->call('DELETE', "/api/registrar/sections/{$cs101b}/instructors/{$fac['F3']}", null, 200, 'B8 unassign F3 CS101-B');

        // B9: enrollments 3-10, each by its own student.
        $enroll('student', $cs101a, 'B9 enrollment 3'); $enroll('student2', $cs101a, 'B9 enrollment 4');
        $enroll('student2', $ge105, 'B9 enrollment 5'); $enroll('student3', $cs101b, 'B9 enrollment 6');
        $enroll('student4', $it211, 'B9 enrollment 7'); $enroll('student5', $it211, 'B9 enrollment 8');
        $enroll('student6', $it221, 'B9 enrollment 9'); $enroll('student7', $it221, 'B9 enrollment 10');

        // B10/B11.
        foreach ([3, 4, 5, 8, 9, 10] as $n) { $reg->call('PATCH', '/api/registrar/enrollments/' . $enr[$n - 1], ['status' => 'approved'], 200, "B10 approve {$n}"); }
        $reg->call('PATCH', '/api/registrar/enrollments/' . $enr[6], ['status' => 'rejected', 'reason' => 'Prerequisite not yet completed (demo).'], 200, 'B10 reject 7');
        $stu['student5']->call('POST', '/api/enrollments/' . $enr[7] . '/drop', null, 200, 'B11 drop 8');

        // B12: refusals (no rows written).
        $stu['student2']->call('POST', '/api/enrollments', ['section_id' => $cs130], 409, 'B12 schedule clash', 'overlaps');
        $stu['student3']->call('POST', '/api/enrollments', ['section_id' => $it221], 409, 'B12 section full', 'full');
        $stu['student3']->call('POST', '/api/enrollments', ['section_id' => $cs100], 409, 'B12 closed term', 'closed');
        (new DemoActor($base))->login('student8@example.test', $passwords['student8@example.test'], 401, 'B12 inactive login');

        // B13: small fictional grading set on CS101-A (demo scale; server runs with APP_GRADE_SCALE=demo). F1 grades both approved
        // students (DEV-0001 -> 1.75, Alma -> INC), submits; the registrar (a different user) finalizes. 2 grades, 6 history rows.
        $f1 = new DemoActor($base);
        $f1->login('faculty@example.test', $passwords['faculty@example.test'], 200, 'login faculty');
        $roster = $f1->call('GET', "/api/faculty/classes/{$cs101a}/students", null, 200, 'B13 roster');
        $ids = array_map(static fn (array $r): int => (int) $r['student_id'], $roster['data']['students'] ?? []);
        if (count($ids) !== 2) { throw new DemoFailed('B13 roster: expected 2 approved students'); }
        $f1->call('PUT', "/api/faculty/classes/{$cs101a}/grades/{$ids[0]}", ['value' => '3.25'], 422, 'B13 invalid code refused');
        $f1->call('PUT', "/api/faculty/classes/{$cs101a}/grades/{$ids[0]}", ['value' => '1.75'], 201, 'B13 enter 1.75');
        $f1->call('PUT', "/api/faculty/classes/{$cs101a}/grades/{$ids[1]}", ['value' => 'INC'], 201, 'B13 enter INC');
        $f1->call('POST', "/api/faculty/classes/{$cs101a}/grades/submit", null, 200, 'B13 submit');
        $f1->call('POST', "/api/registrar/sections/{$cs101a}/grades/finalize", null, 403, 'B13 faculty cannot finalize');
        $reg->call('POST', "/api/registrar/sections/{$cs101a}/grades/finalize", null, 200, 'B13 registrar finalize');
        $stu['student']->call('GET', '/api/grades/me', null, 200, 'B13 student sees own grade', '1.75');
    } catch (Throwable $e) {
        $msg = $e instanceof DemoFailed ? $e->getMessage() : 'unexpected error (' . get_class($e) . ')';
        fwrite(STDERR, "STOPPED: {$msg}. Do not fix data by SQL; report to the owner.\n");
        $status = 1;
    } finally {
        if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    }
    if ($status !== 0) { exit($status); }

    // B13: verify, counts, dump command.
    [$code, $out] = demoRun([PHP_BINARY, 'database/verify.php', '--target=ais_test'], $root,
        ['APP_ENV' => 'testing', 'DB_USERNAME' => 'ais_test_app', 'DB_PASSWORD' => $cfg['app_password']],
        DEMO_SECRET_KEYS);
    echo trim($out) . "\nverify exit {$code} (expected 0)\n";
    $diff = $code !== 0;
    $expected = ['users' => 13, 'user_roles' => 13, 'audit_logs' => 10, 'programs' => 3, 'students' => 8, 'student_academic_history' => 24,
        'faculty' => 3, 'faculty_history' => 6, 'terms' => 3, 'sections' => 8, 'section_meetings' => 12, 'section_instructors' => 7,
        'section_instructor_history' => 9, 'enrollments' => 10, 'enrollment_history' => 20, 'grades' => 2, 'grade_history' => 6,
        'repository_locks' => 5, 'schema_migrations' => 10];
    try {
        $pdo = new PDO('mysql:host=127.0.0.1;port=' . $cfg['port'] . ';dbname=ais_test;charset=utf8mb4', 'ais_test_app', $cfg['app_password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ($expected as $table => $want) { // table names are the constants above
            $got = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            $diff = $diff || $got !== $want;
            printf("%-30s observed %3d expected %3d %s\n", $table, $got, $want, $got === $want ? 'OK' : 'DIFF');
        }
    } catch (Throwable) {
        fwrite(STDERR, "Row count check failed (app account may lack SELECT); run the counts manually.\n");
        $diff = true;
    }
    echo <<<'TXT'

Dump command (NOT run; run from the repo root with a private ais_tester option file outside the repo):
OPTS="--defaults-extra-file=/path/outside/repo/ais_tester.cnf --single-transaction --skip-lock-tables --no-create-info --skip-triggers --skip-add-locks --skip-disable-keys --complete-insert --skip-extended-insert --order-by-primary --hex-blob --skip-dump-date --default-character-set=utf8mb4"
{ echo "-- AIS demo data: FICTIONAL, DEMO ONLY, for ais_test after database/database.sql. Contains password HASHES of fictional demo-only accounts (random passwords kept separately in demo-passwords.txt, never committed). Demo use only; never load outside ais_test."
  echo "-- Generated through seeders + import_json.php + HTTP API per docs/database/DEMO_DATA_SPEC.md. Do not hand-edit."
  mariadb-dump $OPTS --ignore-table=ais_test.schema_migrations --ignore-table=ais_test.repository_locks ais_test
  mariadb-dump $OPTS --replace ais_test repository_locks
} > database/demo_data.sql

TXT;
    echo $diff ? "FINDINGS: differences above are findings; investigate before dumping.\n" : "All counts match the spec.\n";
    exit($diff ? 1 : 0);
}
