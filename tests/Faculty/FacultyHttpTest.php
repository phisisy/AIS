<?php
declare(strict_types=1);

require_once __DIR__ . '/FacultySupport.php';

test('Faculty HTTP: role matrix over real sessions with exact 401, 403 and 404 bodies', function (): void {
    $server = facultyServer();
    try {
        $token = enrollmentLogin($server, 'registrar@example.test');
        $created = $server->request('POST', '/api/registrar/faculty', facultyInput(), $token);
        same([201, 'Faculty profile created.'], [$created['status'], $created['json']['message']]);
        same(['id', 'user_id', 'employee_number', 'first_name', 'last_name', 'department', 'status', 'created_at', 'updated_at'],
            array_keys($created['json']['data']['faculty']));
        $unauth = '{"success":false,"message":"Authentication required.","errors":{}}';
        $denied = '{"success":false,"message":"Access denied.","errors":{}}';
        $missing = '{"success":false,"message":"Faculty profile not found.","errors":{}}';
        // [email, list, show /1, show /99, me, classes]
        $matrix = [
            [null, 401, 401, 401, 401, 401],
            ['admin@example.test', 200, 200, 404, 403, 403],
            ['registrar@example.test', 200, 200, 404, 403, 403],
            ['faculty@example.test', 403, 403, 403, 200, 200],
            ['ana@example.test', 403, 403, 403, 403, 403],
            ['faculty3@example.test', 403, 403, 403, 404, 404],
        ];
        foreach ($matrix as [$email, $list, $one, $none, $me, $classes]) {
            $csrf = enrollmentLogin($server, $email);
            $got = [];
            foreach (['/api/registrar/faculty', '/api/registrar/faculty/1', '/api/registrar/faculty/99', '/api/faculty/me', '/api/faculty/classes'] as $path) {
                $response = $server->request('GET', $path);
                $got[] = $response['status'];
                $body = [401 => $unauth, 403 => $denied][$response['status']] ?? ($response['status'] === 404 ? $missing : null);
                if ($body !== null) {
                    same($body, $response['body']);
                }
            }
            check($got === [$list, $one, $none, $me, $classes], ($email ?? 'anonymous') . ': got ' . json_encode($got));
            if ($email === 'faculty@example.test') {
                same([1, 3], [$server->request('GET', '/api/faculty/me')['json']['data']['faculty']['id'], $server->request('GET', '/api/faculty/me')['json']['data']['faculty']['user_id']]);
                same([], $server->request('GET', '/api/faculty/classes')['json']['data']['classes']);
                same(403, $server->request('POST', '/api/registrar/faculty', facultyInput(['user_id' => 6]), $csrf)['status']);
                same(403, $server->request('PATCH', '/api/registrar/faculty/1', ['status' => 'inactive'], $csrf)['status']);
            }
            if ($email === 'ana@example.test') {
                same(403, $server->request('PATCH', '/api/registrar/faculty/1', ['status' => 'inactive'], $csrf)['status']);
            }
        }
        same('active', json_decode(facultyStoreBytes($server->directory), true)['faculty'][0]['status']);
    } finally { $server->close(); }
});

test('Faculty HTTP: CSRF before validation, JSON only, unknown and immutable fields, route methods', function (): void {
    $server = facultyServer();
    try {
        $token = enrollmentLogin($server, 'registrar@example.test');
        $csrfError = $server->request('POST', '/api/registrar/faculty', facultyInput());
        same([403, 'Invalid CSRF token.'], [$csrfError['status'], $csrfError['json']['message']]);
        same(403, $server->request('POST', '/api/registrar/faculty', ['bogus' => 1])['status']);
        same(201, $server->request('POST', '/api/registrar/faculty', facultyInput(), $token)['status']);
        same(403, $server->request('PATCH', '/api/registrar/faculty/1', ['status' => 'inactive'])['status']);
        $url = (new ReflectionProperty($server, 'url'))->getValue($server) . '/api/registrar/faculty';
        $context = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'content' => 'user_id=6',
            'header' => "Connection: close\r\nContent-Type: text/plain\r\nX-CSRF-Token: {$token}\r\nCookie: " . $server->cookie()]]);
        file_get_contents($url, false, $context);
        preg_match('~^HTTP/\S+ (\d+)~', $http_response_header[0], $match);
        same(415, (int) $match[1]);
        foreach (['id', 'faculty_id', 'created_at', 'password', 'roles'] as $field) {
            $r = $server->request('POST', '/api/registrar/faculty', facultyInput(['user_id' => 6, 'employee_number' => 'EMP-006', $field => 1]), $token);
            same([422, 'Unknown field.'], [$r['status'], $r['json']['errors'][$field] ?? null]);
        }
        foreach (['user_id' => 6, 'employee_number' => 'EMP-099', 'id' => 5] as $field => $value) {
            $r = $server->request('PATCH', '/api/registrar/faculty/1', [$field => $value], $token);
            same([422, 'Unknown field.'], [$r['status'], $r['json']['errors'][$field] ?? null]);
        }
        same(422, $server->request('PATCH', '/api/registrar/faculty/1', [], $token)['status']);
        same(200, $server->request('PATCH', '/api/registrar/faculty/1', ['department' => 'Physics'], $token)['status']);
        same(404, $server->request('GET', '/api/registrar/faculty/abc')['status']);
        same(404, $server->request('PATCH', '/api/registrar/faculty/99', ['status' => 'inactive'], $token)['status']);
        $put = $server->request('PUT', '/api/registrar/faculty/1', ['status' => 'inactive'], $token);
        same(405, $put['status']);
        $allow = array_map('trim', explode(',', $put['headers']['allow'] ?? ''));
        sort($allow);
        same(['GET', 'PATCH'], $allow);
        same(405, $server->request('POST', '/api/faculty/me', [], $token)['status']);
        same(405, $server->request('POST', '/api/faculty/classes', [], $token)['status']);
        same(422, $server->request('GET', '/api/registrar/faculty?per_page=0')['status']);
        same(['id' => 1], ['id' => $server->request('GET', '/api/registrar/faculty?search=emp-001&status=active')['json']['data']['faculty'][0]['id']]);
    } finally { $server->close(); }
});

test('Faculty HTTP: role removal blocks the next request with 403 and deactivation with 401', function (): void {
    $server = facultyServer();
    try {
        $token = enrollmentLogin($server, 'registrar@example.test');
        same(201, $server->request('POST', '/api/registrar/faculty', facultyInput(), $token)['status']);
        enrollmentLogin($server, 'faculty@example.test');
        same(200, $server->request('GET', '/api/faculty/me')['status']);
        changeFacultyAccount($server->directory, 3, static fn (array $row): array => array_replace($row, ['roles' => ['student']]));
        same(403, $server->request('GET', '/api/faculty/me')['status']);
        changeFacultyAccount($server->directory, 3, static fn (array $row): array => array_replace($row, ['roles' => ['faculty'], 'status' => 'inactive']));
        same(401, $server->request('GET', '/api/faculty/me')['status']);
    } finally { $server->close(); }
});
