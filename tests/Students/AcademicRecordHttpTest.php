<?php
declare(strict_types=1);

require_once __DIR__ . '/StudentSupport.php';

test('Academic HTTP: role matrix for records and programs over real sessions', function (): void {
    $server = new AuthHttpServer([], 'public/router.php', studentUserRows());
    seedStudentRuntime($server->directory, false);
    try {
        $server->setCookie('');
        $token = $server->login('registrar@example.test')['json']['data']['csrf_token'];
        same(201, $server->request('POST', '/api/students', anaInput(), $token)['status']);
        same(201, $server->request('POST', '/api/students', anaInput(['user_id' => 5, 'student_number' => '2026-00002']), $token)['status']);
        same(200, $server->request('PATCH', '/api/students/1', ['year_level' => 3], $token)['status']);
        $notFound = '{"success":false,"message":"Student not found.","errors":{}}';
        // [email, GET /1/academic-record, GET /2/academic-record, GET /me/academic-record, GET /api/programs]
        $matrix = [
            [null, 401, 401, 401, 401],
            ['admin@example.test', 200, 200, 403, 200],
            ['registrar@example.test', 200, 200, 403, 200],
            ['faculty@example.test', 403, 403, 403, 200],
            ['ana@example.test', 200, 404, 200, 200],
            ['noprofile@example.test', 404, 404, 404, 200],
        ];
        foreach ($matrix as [$email, $one, $two, $me, $programs]) {
            $server->setCookie('');
            if ($email !== null) {
                $server->login($email);
            }
            $got = [$server->request('GET', '/api/students/1/academic-record')['status'], $server->request('GET', '/api/students/2/academic-record')['status'],
                $server->request('GET', '/api/students/me/academic-record')['status'], $server->request('GET', '/api/programs')['status']];
            check($got === [$one, $two, $me, $programs], ($email ?? 'anonymous') . ': got ' . json_encode($got));
        }
        $server->setCookie('');
        $server->login('ana@example.test');
        same($notFound, $server->request('GET', '/api/students/2/academic-record')['body']);
        same($notFound, $server->request('GET', '/api/students/999/academic-record')['body']);
        $record = $server->request('GET', '/api/students/me/academic-record?per_page=2&page=2')['json']['data']['academic_record'];
        same(['page' => 2, 'per_page' => 2, 'total' => 4], $record['history']['pagination']);
        same([['field' => 'academic_status', 'to' => 'active'], ['field' => 'year_level', 'to' => 3]],
            array_map(static fn (array $e): array => ['field' => $e['field'], 'to' => $e['to']], $record['history']['entries']));
        same(['available' => false], $record['enrollment_summary']);
        same(422, $server->request('GET', '/api/students/me/academic-record?per_page=0')['status']);
        same(405, $server->request('POST', '/api/programs', [])['status']);
        same(['ASCT', 'BSCS'], array_column($server->request('GET', '/api/programs')['json']['data']['programs'], 'code'));
    } finally {
        $server->close();
    }
});

test('Academic HTTP: history and record are retained when the account is deactivated or loses the student role', function (): void {
    $server = new AuthHttpServer([], 'public/router.php', studentUserRows());
    seedStudentRuntime($server->directory, false);
    try {
        $server->setCookie('');
        $token = $server->login('registrar@example.test')['json']['data']['csrf_token'];
        $registrarCookie = $server->cookie();
        same(201, $server->request('POST', '/api/students', anaInput(), $token)['status']);
        same(200, $server->request('PATCH', '/api/students/1', ['academic_status' => 'on_leave'], $token)['status']);
        $before = $server->request('GET', '/api/students/1/academic-record')['json']['data']['academic_record'];
        $users = new App\Auth\Repositories\MockUserRepository(new App\Core\JsonStore($server->directory . '/auth/users.json'));
        $server->setCookie('');
        $server->login('ana@example.test');
        $anaCookie = $server->cookie();
        $users->transaction(static fn ($tx) => $tx->update(4, ['roles' => ['faculty']]));
        same(403, $server->request('GET', '/api/students/me/academic-record')['status']);
        same(403, $server->request('GET', '/api/students/1/academic-record')['status']);
        $users->transaction(static fn ($tx) => $tx->update(4, ['roles' => ['student'], 'status' => 'inactive']));
        $server->setCookie($anaCookie);
        same(401, $server->request('GET', '/api/students/me/academic-record')['status']);
        $server->setCookie($registrarCookie);
        same($before, $server->request('GET', '/api/students/1/academic-record')['json']['data']['academic_record']);
        same('on_leave', $before['student']['academic_status']);
    } finally {
        $server->close();
    }
});
