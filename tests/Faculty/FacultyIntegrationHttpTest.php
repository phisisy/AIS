<?php
declare(strict_types=1);

require_once __DIR__ . '/FacultySupport.php';

test('Faculty integration: production bootstrap binds the real lookup end to end', function (): void {
    $server = facultyServer();
    try {
        $registrar = enrollmentLogin($server, 'registrar@example.test');
        same(201, $server->request('POST', '/api/registrar/terms', termInput(), $registrar)['status']);
        $body = ['term_id' => 1, 'course_code' => 'CS101', 'course_title' => 'Introduction to Computing', 'units' => 3,
            'section_code' => 'A', 'capacity' => 40, 'meetings' => [['day' => 'MON', 'start' => '09:00', 'end' => '10:00', 'room' => 'R-101']]];
        same(201, $server->request('POST', '/api/registrar/sections', $body, $registrar)['status']);
        $body['section_code'] = 'B';
        $body['meetings'][0]['day'] = 'TUE';
        same(201, $server->request('POST', '/api/registrar/sections', $body, $registrar)['status']);
        $profile = $server->request('POST', '/api/registrar/faculty', facultyInput(), $registrar);
        same([201, 1], [$profile['status'], $profile['json']['data']['faculty']['id']]);

        $assign = static fn (int $section, ?string $csrf = null): array
            => $server->request('POST', "/api/registrar/sections/{$section}/instructors", ['faculty_id' => 1], $csrf ?? enrollmentLogin($server, 'registrar@example.test'));
        $enrollmentBytes = static fn (): string => file_get_contents($server->directory . '/enrollment/enrollment.json');
        $classIds = static function () use ($server): array {
            enrollmentLogin($server, 'faculty@example.test');
            return array_column($server->request('GET', '/api/faculty/classes')['json']['data']['classes'], 'section_id');
        };

        $first = $assign(1, $registrar);
        same([201, [['faculty_id' => 1, 'name' => 'Reyes, Ana']]], [$first['status'], $first['json']['data']['section']['instructors']]);
        enrollmentLogin($server, 'faculty@example.test');
        same(200, $server->request('GET', '/api/faculty/me')['status']);
        same([1], $classIds());

        // inactive profile: new assignment refused with the single A8 message, retained one grants nothing but keeps its name
        $registrar = enrollmentLogin($server, 'registrar@example.test');
        same(200, $server->request('PATCH', '/api/registrar/faculty/1', ['status' => 'inactive'], $registrar)['status']);
        $before = $enrollmentBytes();
        $refused = $assign(2, $registrar);
        same([422, ['faculty_id' => 'Unknown or inactive faculty member.']], [$refused['status'], $refused['json']['errors']]);
        same($before, $enrollmentBytes());
        same([], $classIds());
        $registrar = enrollmentLogin($server, 'registrar@example.test');
        same('Reyes, Ana', $server->request('GET', '/api/sections/1')['json']['data']['section']['instructors'][0]['name']);

        // DELETE works regardless
        same(200, $server->request('DELETE', '/api/registrar/sections/1/instructors/1', null, $registrar)['status']);
        same(404, $server->request('DELETE', '/api/registrar/sections/1/instructors/1', null, $registrar)['status']);

        // reactivation: one audited history entry; assignment works again; student/faculty cannot reactivate
        $history = count(json_decode(facultyStoreBytes($server->directory), true)['history']);
        same(200, $server->request('PATCH', '/api/registrar/faculty/1', ['status' => 'active'], $registrar)['status']);
        $entries = json_decode(facultyStoreBytes($server->directory), true)['history'];
        same($history + 1, count($entries));
        same([2, 'status', 'active'], [end($entries)['actor_id'], end($entries)['field'], end($entries)['to']]);
        same(201, $assign(2, $registrar)['status']);
        same(201, $assign(1, $registrar)['status']);
        same([1, 2], $classIds());
        foreach (['ana@example.test', 'faculty@example.test'] as $email) {
            $csrf = enrollmentLogin($server, $email);
            same(403, $server->request('PATCH', '/api/registrar/faculty/1', ['status' => 'active'], $csrf)['status']);
        }

        // role removal blocks new assignment, restoring it allows again
        $registrar = enrollmentLogin($server, 'registrar@example.test');
        same(200, $server->request('DELETE', '/api/registrar/sections/1/instructors/1', null, $registrar)['status']);
        changeFacultyAccount($server->directory, 3, static fn (array $row): array => array_replace($row, ['roles' => ['student']]));
        same(422, $assign(1)['status']);
        changeFacultyAccount($server->directory, 3, static fn (array $row): array => array_replace($row, ['roles' => ['faculty']]));
        same(201, $assign(1)['status']);
    } finally { $server->close(); }
});
