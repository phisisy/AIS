<?php
declare(strict_types=1);

require_once __DIR__ . '/StudentSupport.php';

function studentServer(): AuthHttpServer
{
    $server = new AuthHttpServer([], 'public/router.php', studentUserRows());
    seedStudentRuntime($server->directory, false);
    return $server;
}

function asUser(AuthHttpServer $server, ?string $email): ?string
{
    $server->setCookie('');
    return $email === null ? null : $server->login($email)['json']['data']['csrf_token'];
}

test('Students HTTP: role matrix over real sessions with exact 404 and no private fields for non-staff', function (): void {
    $server = studentServer();
    try {
        $token = asUser($server, 'registrar@example.test');
        $created = $server->request('POST', '/api/students', anaInput(), $token);
        same(201, $created['status']);
        same(['id', 'user_id', 'student_number', 'first_name', 'middle_name', 'last_name', 'program_code', 'year_level',
            'academic_status', 'admission_year', 'created_at', 'updated_at'], array_keys($created['json']['data']['student']));
        same(201, $server->request('POST', '/api/students', anaInput(['user_id' => 5, 'student_number' => '2026-00002', 'first_name' => 'Ben']), $token)['status']);

        $notFound = '{"success":false,"message":"Student not found.","errors":{}}';
        $denied = '{"success":false,"message":"Access denied.","errors":{}}';
        // [email, GET list, GET /1, GET /2, GET /me, PATCH /1]
        $matrix = [
            [null, 401, 401, 401, 401, 401],
            ['admin@example.test', 200, 200, 200, 403, 200],
            ['registrar@example.test', 200, 200, 200, 403, 200],
            ['faculty@example.test', 403, 404, 404, 403, 403],
            ['ana@example.test', 403, 200, 404, 200, 403],
            ['noprofile@example.test', 403, 404, 404, 404, 403],
        ];
        foreach ($matrix as [$email, $list, $one, $two, $me, $patch]) {
            $csrf = asUser($server, $email);
            $who = $email ?? 'anonymous';
            $got = [$server->request('GET', '/api/students')['status'], $server->request('GET', '/api/students/1')['status'],
                $server->request('GET', '/api/students/2')['status'], $server->request('GET', '/api/students/me')['status'],
                $server->request('PATCH', '/api/students/1', ['academic_status' => 'graduated'], $csrf)['status']];
            check($got === [$list, $one, $two, $me, $patch], "{$who}: got " . json_encode($got));
            if ($email === 'faculty@example.test') {
                same($notFound, $server->request('GET', '/api/students/1')['body']);
                same($notFound, $server->request('GET', '/api/students/999')['body']);
                same($denied, $server->request('GET', '/api/students')['body']);
            }
        }
        // The ana@ row ran after admin/registrar PATCHes; only staff changed the status.
        asUser($server, 'ana@example.test');
        $own = $server->request('GET', '/api/students/me');
        same('graduated', $own['json']['data']['student']['academic_status']);
        check(!str_contains($own['body'], 'password') && !str_contains($own['body'], '$2y$'), 'No credential data.');
    } finally {
        $server->close();
    }
});

test('Students HTTP: CSRF on writes, unknown and immutable fields rejected, invalid input safe', function (): void {
    $server = studentServer();
    try {
        $token = asUser($server, 'registrar@example.test');
        same('Invalid CSRF token.', $server->request('POST', '/api/students', anaInput())['json']['message']);
        same(201, $server->request('POST', '/api/students', anaInput(), $token)['status']);
        foreach ([['student_number' => 'NEW-1'], ['user_id' => 5], ['password' => 'x'], ['role' => 'admin']] as $body) {
            same(422, $server->request('PATCH', '/api/students/1', $body, $token)['status']);
        }
        same(422, $server->request('POST', '/api/students', anaInput(['user_id' => 5, 'student_number' => 'N-2', 'id' => 7]), $token)['status']);
        same(409, $server->request('POST', '/api/students', anaInput(['user_id' => 5]), $token)['status']);
        same(404, $server->request('GET', '/api/students/abc')['status']);
        same(422, $server->request('GET', '/api/students?per_page=500')['status']);
        same(422, $server->request('GET', '/api/students?search[]=x')['status']);
        same('2026-00001', $server->request('GET', '/api/students/1')['json']['data']['student']['student_number']);
    } finally {
        $server->close();
    }
});

test('Students HTTP: a broken or corrupt student store fails safely with a generic 500 and writes nothing', function (): void {
    $server = studentServer();
    try {
        $token = asUser($server, 'registrar@example.test');
        file_put_contents($server->directory . '/students/students.json', '{"students": [corrupt');
        foreach ([['GET', '/api/students', null], ['POST', '/api/students', anaInput()], ['GET', '/api/students/1', null]] as [$method, $path, $body]) {
            $response = $server->request($method, $path, $body, $token);
            same([500, 'An internal error occurred.'], [$response['status'], $response['json']['message']]);
        }
        same('{"students": [corrupt', file_get_contents($server->directory . '/students/students.json'));
    } finally {
        $server->close();
    }
});

test('Students HTTP: profiles are retained when the account is deactivated or loses the student role (owner decision 3)', function (): void {
    $server = studentServer();
    try {
        $token = asUser($server, 'registrar@example.test');
        same(201, $server->request('POST', '/api/students', anaInput(['academic_status' => 'on_leave']), $token)['status']);
        $profile = static fn (AuthHttpServer $s): array => $s->request('GET', '/api/students/1')['json']['data']['student'];
        $before = $profile($server);
        $users = new App\Auth\Repositories\MockUserRepository(new App\Core\JsonStore($server->directory . '/auth/users.json'));

        // Ana loses the student role (now faculty only) while signed in.
        asUser($server, 'ana@example.test');
        $anaCookie = $server->cookie();
        same(200, $server->request('GET', '/api/students/me')['status']);
        $users->transaction(static fn ($tx) => $tx->update(4, ['roles' => ['faculty']]));
        same(403, $server->request('GET', '/api/students/me')['status']);
        same('{"success":false,"message":"Student not found.","errors":{}}', $server->request('GET', '/api/students/1')['body']);

        // Then the account is deactivated: no authentication at all.
        $users->transaction(static fn ($tx) => $tx->update(4, ['status' => 'inactive']));
        $server->setCookie($anaCookie);
        same(401, $server->request('GET', '/api/students/1')['status']);
        same(401, (function () use ($server) { $server->setCookie(''); return $server->login('ana@example.test'); })()['status']);

        // Registrar and admin still see the unchanged record: same number, same academic status and history.
        foreach (['registrar@example.test', 'admin@example.test'] as $staff) {
            asUser($server, $staff);
            same($before, $profile($server));
        }
        same('on_leave', $before['academic_status']);
        same('2026-00001', $before['student_number']);

        // Restoring the role and account restores self-access to the same record.
        $users->transaction(static fn ($tx) => $tx->update(4, ['status' => 'active', 'roles' => ['student']]));
        asUser($server, 'ana@example.test');
        same($before, $server->request('GET', '/api/students/me')['json']['data']['student']);
    } finally {
        $server->close();
    }
});
