<?php
declare(strict_types=1);

// UNVERIFIED: written without being executed (compromised host, owner forbids running tests).

require_once __DIR__ . '/GradeSupport.php';
require_once __DIR__ . '/FacultySupport.php';

test('Grade HTTP: production bootstrap fails closed (409 grade_scale), role matrix and CSRF, nothing written', function (): void {
    $server = facultyServer();
    try {
        $registrar = enrollmentLogin($server, 'registrar@example.test');
        same(201, $server->request('POST', '/api/registrar/terms', termInput(), $registrar)['status']);
        $section = ['term_id' => 1, 'course_code' => 'CS101', 'course_title' => 'Introduction to Computing', 'units' => 3,
            'section_code' => 'A', 'capacity' => 40, 'meetings' => [['day' => 'MON', 'start' => '09:00', 'end' => '10:00', 'room' => 'R-101']]];
        same(201, $server->request('POST', '/api/registrar/sections', $section, $registrar)['status']);
        same(201, $server->request('POST', '/api/registrar/faculty', facultyInput(), $registrar)['status']);
        same(201, $server->request('POST', '/api/registrar/sections/1/instructors', ['faculty_id' => 1], $registrar)['status']);

        // assigned faculty: entry is refused with the generic scale message and nothing is written
        $faculty = enrollmentLogin($server, 'faculty@example.test');
        $put = $server->request('PUT', '/api/faculty/classes/1/grades/1', ['value' => '1.00'], $faculty);
        same([409, ['grade_scale' => 'Grade entry is not available yet.']], [$put['status'], $put['json']['errors']]);
        $list = $server->request('GET', '/api/faculty/classes/1/grades');
        same([200, []], [$list['status'], $list['json']['data']['grades']]);
        check(!file_exists($server->directory . '/faculty/grades.json'), 'No grade store may be written.');
        same(403, $server->request('PUT', '/api/faculty/classes/1/grades/1', ['value' => '1.00'])['status']);   // CSRF
        same(403, $server->request('POST', '/api/faculty/classes/1/grades/submit', null)['status']);
        same(403, $server->request('GET', '/api/faculty/classes/2/grades')['status']);                           // unknown section

        $registrar = enrollmentLogin($server, 'registrar@example.test');
        $correction = $server->request('POST', '/api/registrar/grades/1/corrections', ['value' => '1.00', 'reason' => 'x', 'expected_seq' => 1], $registrar);
        same([409, ['grade_scale' => 'Grade entry is not available yet.']], [$correction['status'], $correction['json']['errors']]);
        same(404, $server->request('POST', '/api/registrar/sections/9/grades/finalize', null, $registrar)['status']);
        same(404, $server->request('POST', '/api/registrar/sections/9/grades/return', ['reason' => 'x'], $registrar)['status']);
        same(200, $server->request('GET', '/api/registrar/sections/1/grades')['status']);
        same(422, $server->request('POST', '/api/registrar/sections/1/grades/finalize', ['a' => 1], $registrar)['status']);

        // role matrix: [method, path, body, allowed emails]
        $routes = [
            ['GET', '/api/faculty/classes/1/grades', null, ['faculty@example.test']],
            ['PUT', '/api/faculty/classes/1/grades/1', ['value' => '1.00'], ['faculty@example.test']],
            ['POST', '/api/faculty/classes/1/grades/submit', null, ['faculty@example.test']],
            ['GET', '/api/registrar/sections/1/grades', null, ['registrar@example.test', 'admin@example.test']],
            ['POST', '/api/registrar/sections/1/grades/finalize', null, ['registrar@example.test', 'admin@example.test']],
            ['POST', '/api/registrar/sections/1/grades/return', ['reason' => 'x'], ['registrar@example.test', 'admin@example.test']],
            ['POST', '/api/registrar/grades/1/corrections', ['value' => '1.00', 'reason' => 'x', 'expected_seq' => 1], ['registrar@example.test', 'admin@example.test']],
            ['GET', '/api/grades/me', null, ['ana@example.test']],
        ];
        foreach ($routes as [$method, $path, $body, $allowed]) {
            foreach ([null, 'admin@example.test', 'registrar@example.test', 'faculty@example.test', 'ana@example.test'] as $email) {
                if ($email !== null && in_array($email, $allowed, true)) {
                    continue;
                }
                $csrf = enrollmentLogin($server, $email);
                $response = $server->request($method, $path, $body, $csrf);
                same($email === null ? 401 : 403, $response['status'], "{$method} {$path} {$email}");
            }
        }
        // a student without a profile sees an empty list; a client student_id is refused
        enrollmentLogin($server, 'ana@example.test');
        $mine = $server->request('GET', '/api/grades/me');
        same([200, []], [$mine['status'], $mine['json']['data']['grades']]);
        same(422, $server->request('GET', '/api/grades/me?student_id=2')['status']);
    } finally { $server->close(); }
});
