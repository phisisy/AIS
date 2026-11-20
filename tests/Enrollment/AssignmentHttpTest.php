<?php
declare(strict_types=1);

require_once __DIR__ . '/EnrollmentSupport.php';

function assignmentHttpBody(): array
{
    return ['term_id' => 1, 'course_code' => 'CS101', 'course_title' => 'Introduction to Computing',
        'units' => 3, 'section_code' => 'A', 'capacity' => 40,
        'meetings' => [['day' => 'MON', 'start' => '09:00', 'end' => '10:00', 'room' => 'R-101']]];
}

test('Assignment HTTP: real-session role matrix, CSRF and route methods', function (): void {
    $server = enrollmentServer();
    try {
        $token = enrollmentLogin($server, 'registrar@example.test');
        same(201, $server->request('POST', '/api/registrar/terms', termInput(), $token)['status']);
        same(201, $server->request('POST', '/api/registrar/sections', assignmentHttpBody(), $token)['status']);
        $post = '/api/registrar/sections/1/instructors';
        $delete = $post . '/10';
        foreach ([[null, 401], ['ana@example.test', 403], ['faculty@example.test', 403]] as [$email, $status]) {
            $csrf = enrollmentLogin($server, $email);
            $expected = $email === null ? '{"success":false,"message":"Authentication required.","errors":{}}'
                : '{"success":false,"message":"Access denied.","errors":{}}';
            $response = $server->request('POST', $post, assignInput(), $csrf);
            same([$status, $expected], [$response['status'], $response['body']]);
            $response = $server->request('DELETE', $delete, null, $csrf);
            same([$status, $expected], [$response['status'], $response['body']]);
            if ($email !== null) {
                same(403, $server->request('POST', $post, ['unknown' => 1], $csrf)['status']);
                same(403, $server->request('DELETE', $delete, ['unknown' => 1], $csrf)['status']);
            }
        }
        foreach (['registrar@example.test', 'admin@example.test'] as $email) {
            $csrf = enrollmentLogin($server, $email);
            same([403, 'Invalid CSRF token.'], (function () use ($server, $post): array {
                $r = $server->request('POST', $post, assignInput());
                return [$r['status'], $r['json']['message']];
            })());
            same(403, $server->request('DELETE', $delete)['status']);
            same([422, ['faculty_id' => 'Unknown or inactive faculty member.']], (function () use ($server, $post, $csrf): array {
                $r = $server->request('POST', $post, assignInput(), $csrf);
                return [$r['status'], $r['json']['errors']];
            })());
        }
        $csrf = enrollmentLogin($server, 'registrar@example.test');
        same('{"success":false,"message":"Instructor assignment not found.","errors":{}}',
            $server->request('DELETE', $delete, null, $csrf)['body']);
        same('{"success":false,"message":"Section not found.","errors":{}}',
            $server->request('DELETE', '/api/registrar/sections/999/instructors/10', null, $csrf)['body']);
        same([422, ['other' => 'Unknown field.']], (function () use ($server, $post, $csrf): array {
            $r = $server->request('POST', $post, ['faculty_id' => 10, 'other' => 1], $csrf);
            return [$r['status'], $r['json']['errors']];
        })());
        same([422, ['other' => 'Unknown field.']], (function () use ($server, $delete, $csrf): array {
            $r = $server->request('DELETE', $delete, ['other' => 1], $csrf);
            return [$r['status'], $r['json']['errors']];
        })());
        $url = (new ReflectionProperty($server, 'url'))->getValue($server);
        $context = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'content' => 'faculty_id=10',
            'header' => "Connection: close\r\nContent-Type: text/plain\r\nX-CSRF-Token: {$csrf}\r\nCookie: " . $server->cookie()]]);
        file_get_contents($url . $post, false, $context);
        preg_match('~^HTTP/\S+ (\d+)~', $http_response_header[0], $match);
        same(415, (int) $match[1]);
        same(404, $server->request('GET', $delete . 'abc')['status']);
        $method = $server->request('GET', $delete);
        same(405, $method['status']);
        same('DELETE', $method['headers']['allow'] ?? null);
    } finally { $server->close(); }
});

test('Assignment HTTP: reads expose instructors and seeded DELETE audits once', function (): void {
    $server = enrollmentServer();
    try {
        $token = enrollmentLogin($server, 'registrar@example.test');
        $server->request('POST', '/api/registrar/terms', termInput(), $token);
        $server->request('POST', '/api/registrar/sections', assignmentHttpBody(), $token);
        seedAssignment($server->directory, 1, 10);
        foreach (['ana@example.test', 'faculty@example.test', 'registrar@example.test', 'admin@example.test'] as $email) {
            enrollmentLogin($server, $email);
            same([['faculty_id' => 10, 'name' => null]], $server->request('GET', '/api/sections/1')['json']['data']['section']['instructors']);
            same([['faculty_id' => 10, 'name' => null]], $server->request('GET', '/api/sections')['json']['data']['sections'][0]['instructors']);
        }
        $token = enrollmentLogin($server, 'registrar@example.test');
        $path = '/api/registrar/sections/1/instructors/10';
        $deleted = $server->request('DELETE', $path, null, $token);
        same(200, $deleted['status']);
        same([200, 'Instructor unassigned.', []], [$deleted['status'], $deleted['json']['message'], $deleted['json']['data']['section']['instructors']]);
        same(404, $server->request('DELETE', $path, null, $token)['status']);
        same(['assigned', 'unassigned'], array_column(json_decode(storeBytes($server->directory), true)['assignment_history'], 'action'));
    } finally { $server->close(); }
});

test('Assignment HTTP: production identity refuses every id without changing the store', function (): void {
    $server = enrollmentServer();
    try {
        $token = enrollmentLogin($server, 'registrar@example.test');
        $server->request('POST', '/api/registrar/terms', termInput(), $token);
        $server->request('POST', '/api/registrar/sections', assignmentHttpBody(), $token);
        $before = storeBytes($server->directory);
        foreach ([1, 3, 999] as $id) {
            $response = $server->request('POST', '/api/registrar/sections/1/instructors', ['faculty_id' => $id], $token);
            same([422, ['faculty_id' => 'Unknown or inactive faculty member.']], [$response['status'], $response['json']['errors']]);
            same($before, storeBytes($server->directory));
        }
    } finally { $server->close(); }
});
