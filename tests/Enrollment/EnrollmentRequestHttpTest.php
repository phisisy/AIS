<?php
declare(strict_types=1);

require_once __DIR__ . '/EnrollmentSupport.php';
require_once dirname(__DIR__) . '/Students/StudentSupport.php';

/**
 * Real router, real sessions. Students 1 (ana, user 4) and 2 (ben, user 5) exist; user 7 has no profile.
 * Open current term 1; sections: 1 CS101 MON 09:00-10:30, 2 CS102 MON 10:00-11:00 (capacity 1), 3 CS104 tba, 4 CS105 TUE 09:00-10:00.
 * @return array{0: AuthHttpServer, 1: string} server and the registrar CSRF token
 */
function t22HttpServer(): array
{
    $server = new AuthHttpServer([], 'public/router.php', studentUserRows());
    try {
        seedStudentRuntime($server->directory, false);
        $token = enrollmentLogin($server, 'registrar@example.test');
        foreach ([['/api/students', anaInput()], ['/api/students', anaInput(['user_id' => 5, 'student_number' => '2026-00002'])],
                  ['/api/registrar/terms', termInput(['is_current' => true, 'enrollment_open' => true])],
                  ['/api/registrar/sections', sectionInput()],
                  ['/api/registrar/sections', sectionInput(['course_code' => 'CS102', 'capacity' => 1, 'meetings' => [meeting('MON', '10:00', '11:00')]])],
                  ['/api/registrar/sections', sectionInput(['course_code' => 'CS104', 'delivery_mode' => 'tba', 'meetings' => []])],
                  ['/api/registrar/sections', sectionInput(['course_code' => 'CS105', 'meetings' => [meeting('TUE', '09:00', '10:00')]])]] as [$path, $body]) {
            same(201, $server->request('POST', $path, $body, $token)['status']);
        }
        return [$server, $token];
    } catch (Throwable $exception) {
        $server->close();
        throw $exception;
    }
}

test('Enrollment HTTP: role matrix, CSRF, body rules and missing profile', function (): void {
    [$server] = t22HttpServer();
    try {
        $anonymous = '{"success":false,"message":"Authentication required.","errors":{}}';
        $denied = '{"success":false,"message":"Access denied.","errors":{}}';
        $routes = [
            ['POST', '/api/enrollments', ['section_id' => 1], ['ana@example.test']],
            ['GET', '/api/enrollments/me', null, ['ana@example.test']],
            ['POST', '/api/enrollments/1/drop', null, ['ana@example.test']],
            ['GET', '/api/schedules/me', null, ['ana@example.test']],
            ['GET', '/api/registrar/enrollments', null, ['registrar@example.test', 'admin@example.test']],
            ['PATCH', '/api/registrar/enrollments/1', ['status' => 'approved'], ['registrar@example.test', 'admin@example.test']],
        ];
        foreach ($routes as [$method, $path, $body, $allowed]) {
            foreach ([null, 'admin@example.test', 'registrar@example.test', 'faculty@example.test', 'ana@example.test'] as $email) {
                if ($email !== null && in_array($email, $allowed, true)) {
                    continue;
                }
                $csrf = enrollmentLogin($server, $email);
                $response = $server->request($method, $path, $body, $csrf);
                same([$email === null ? 401 : 403, $email === null ? $anonymous : $denied], [$response['status'], $response['body']], "{$method} {$path} {$email}");
            }
        }
        // Writes need the CSRF token even for the right role.
        enrollmentLogin($server, 'ana@example.test');
        foreach ([['POST', '/api/enrollments', ['section_id' => 1]], ['POST', '/api/enrollments/1/drop', null]] as [$method, $path, $body]) {
            $response = $server->request($method, $path, $body);
            same([403, 'Invalid CSRF token.'], [$response['status'], $response['json']['message']]);
        }
        enrollmentLogin($server, 'registrar@example.test');
        same(403, $server->request('PATCH', '/api/registrar/enrollments/1', ['status' => 'approved'])['status']);
        // Body rules: the student always comes from the session.
        $csrf = enrollmentLogin($server, 'ana@example.test');
        $response = $server->request('POST', '/api/enrollments', ['section_id' => 1, 'student_id' => 2], $csrf);
        same([422, ['student_id' => 'Unknown field.']], [$response['status'], $response['json']['errors']]);
        same([422, ['section_id' => 'This field is required.']], (function () use ($server, $csrf): array {
            $r = $server->request('POST', '/api/enrollments', [], $csrf);
            return [$r['status'], $r['json']['errors']];
        })());
        same(422, $server->request('POST', '/api/enrollments/1/drop', ['x' => 1], $csrf)['status']);
        same(422, $server->request('GET', '/api/enrollments/me?status=bogus')['status']);
        same(422, $server->request('GET', '/api/schedules/me?term_id=abc')['status']);
        same(404, $server->request('GET', '/api/schedules/me?term_id=99')['status']);
        same(405, $server->request('GET', '/api/enrollments')['status']);
        // A student without a profile gets 403 on every student route.
        $csrf = enrollmentLogin($server, 'noprofile@example.test');
        foreach ([['POST', '/api/enrollments', ['section_id' => 1]], ['GET', '/api/enrollments/me', null],
                  ['POST', '/api/enrollments/1/drop', null], ['GET', '/api/schedules/me', null]] as [$method, $path, $body]) {
            same(403, $server->request($method, $path, $body, $csrf)['status'], "{$method} {$path}");
        }
        same([], serverEnrollmentStatuses($server)); // no enrollment row was written (JSON list in mock, SQL table on C3/C4)
    } finally { $server->close(); }
});

test('Enrollment HTTP: request, ownership, decisions, required reason, capacity, schedule and decided_by visibility', function (): void {
    [$server, $registrarCsrf] = t22HttpServer();
    try {
        $ana = enrollmentLogin($server, 'ana@example.test');
        $created = $server->request('POST', '/api/enrollments', ['section_id' => 1], $ana);
        same([201, 'pending', 1, 1], [$created['status'], $created['json']['data']['enrollment']['status'],
            $created['json']['data']['enrollment']['student_id'], $created['json']['data']['enrollment']['term_id']]);
        same(false, array_key_exists('decided_by', $created['json']['data']['enrollment']));
        same(409, $server->request('POST', '/api/enrollments', ['section_id' => 1], $ana)['status']); // duplicate
        $ben = enrollmentLogin($server, 'ben@example.test');
        same(201, $server->request('POST', '/api/enrollments', ['section_id' => 2], $ben)['status']);
        $clash = $server->request('POST', '/api/enrollments', ['section_id' => 1], $ben);
        same(409, $clash['status']);
        check(str_contains($clash['json']['errors']['section_id'], 'CS102-A (section 2)'), $clash['body']);
        // Ownership: another student's drop is a 404, and /me shows only the caller's rows.
        same('{"success":false,"message":"Enrollment not found.","errors":{}}', $server->request('POST', '/api/enrollments/1/drop', null, $ben)['body']);
        same([2], array_column($server->request('GET', '/api/enrollments/me')['json']['data']['enrollments'], 'id'));
        enrollmentLogin($server, 'ana@example.test');
        same([1], array_column($server->request('GET', '/api/enrollments/me')['json']['data']['enrollments'], 'id'));
        // Staff: decided_by is visible; rejecting needs a reason.
        $registrar = enrollmentLogin($server, 'registrar@example.test');
        $list = $server->request('GET', '/api/registrar/enrollments?status=pending')['json']['data'];
        same([[1, true], [2, true]], array_map(static fn (array $r): array => [$r['id'], array_key_exists('decided_by', $r)], $list['enrollments']));
        same(2, $list['pagination']['total']);
        $noReason = $server->request('PATCH', '/api/registrar/enrollments/1', ['status' => 'rejected'], $registrar);
        same([422, ['reason']], [$noReason['status'], array_keys($noReason['json']['errors'])]);
        same(422, $server->request('PATCH', '/api/registrar/enrollments/1', ['status' => 'pending'], $registrar)['status']);
        same(404, $server->request('PATCH', '/api/registrar/enrollments/999', ['status' => 'approved'], $registrar)['status']);
        $rejected = $server->request('PATCH', '/api/registrar/enrollments/1', ['status' => 'rejected', 'reason' => 'Missing documents'], $registrar);
        same([200, 'rejected', 'Missing documents', 2], [$rejected['status'], $rejected['json']['data']['enrollment']['status'],
            $rejected['json']['data']['enrollment']['reason'], $rejected['json']['data']['enrollment']['decided_by']]);
        same(409, $server->request('PATCH', '/api/registrar/enrollments/1', ['status' => 'approved'], $registrar)['status']); // final
        same(200, $server->request('PATCH', '/api/registrar/enrollments/2', ['status' => 'approved'], $registrar)['status']);
        same(409, $server->request('PATCH', '/api/registrar/enrollments/2', ['status' => 'approved'], $registrar)['status']); // no replay
        // The student sees the reason, never decided_by; the section is now full.
        $ana = enrollmentLogin($server, 'ana@example.test');
        $mine = $server->request('GET', '/api/enrollments/me')['json']['data']['enrollments'][0];
        same(['rejected', 'Missing documents', false], [$mine['status'], $mine['reason'], array_key_exists('decided_by', $mine)]);
        $full = $server->request('POST', '/api/enrollments', ['section_id' => 2], $ana);
        same(409, $full['status']);
        check(str_contains($full['json']['errors']['section_id'], 'full'), $full['body']);
        same([1, 0], [$server->request('GET', '/api/sections/2')['json']['data']['section']['approved_count'],
            $server->request('GET', '/api/sections/2')['json']['data']['section']['remaining']]);
        // Schedule: approved only, tba with empty meetings, instructors from the T-21 presenter.
        if (enrollmentOnMysql()) {
            seedFacultyProfile($server); // SQL needs the faculty row for the assignment (FK)
        }
        seedServerAssignment($server, 3, 10);
        same(201, $server->request('POST', '/api/enrollments', ['section_id' => 3], $ana)['status']);
        same(0, count($server->request('GET', '/api/schedules/me')['json']['data']['items'])); // still pending
        $registrar = enrollmentLogin($server, 'registrar@example.test');
        same(200, $server->request('PATCH', '/api/registrar/enrollments/3', ['status' => 'approved'], $registrar)['status']);
        $scheduleItems = static fn (?string $name): array => [['enrollment_id' => 3, 'section_id' => 3, 'course_code' => 'CS104',
            'course_title' => 'Introduction to Computing', 'section_code' => 'A', 'units' => 3, 'delivery_mode' => 'tba', 'meetings' => [],
            'instructors' => [['faculty_id' => 10, 'name' => $name]]]];
        enrollmentLogin($server, 'ana@example.test');
        $schedule = $server->request('GET', '/api/schedules/me')['json']['data'];
        same(1, $schedule['term_id']);
        if (!enrollmentOnMysql()) {
            // Mock only: no faculty profile yet, so the original null-name fallback assertion still holds.
            same($scheduleItems(null), $schedule['items']);
            seedFacultyProfile($server);
            $schedule = $server->request('GET', '/api/schedules/me')['json']['data'];
        }
        // Both modes (incl. C3/C4): with a real profile the schedule shows the real instructor name.
        same($scheduleItems('Reyes, Ana'), $schedule['items']);
        enrollmentLogin($server, 'ben@example.test');
        $item = $server->request('GET', '/api/schedules/me?term_id=1')['json']['data']['items'][0];
        same(['CS102', [['day' => 'MON', 'start' => '10:00', 'end' => '11:00', 'room' => 'R-101']]], [$item['course_code'], $item['meetings']]);
        // Append-only history: one row per transition.
        if (!enrollmentOnMysql()) { // SQL history has a per-enrollment seq only, so the global order is a mock-only fact
            same(['requested', 'requested', 'rejected', 'approved', 'requested', 'approved'],
                array_column(json_decode(storeBytes($server->directory), true)['enrollment_history'], 'action'));
        }
        same([1 => ['requested', 'rejected'], 2 => ['requested', 'approved'], 3 => ['requested', 'approved']], serverEnrollmentActionsById($server));
    } finally { $server->close(); }
});

test('Enrollment HTTP: closed term (OQ-2, N-1), the OQ-3 section PATCH clash and the academic-record summary', function (): void {
    [$server, $registrarCsrf] = t22HttpServer();
    try {
        $ana = enrollmentLogin($server, 'ana@example.test');
        same(201, $server->request('POST', '/api/enrollments', ['section_id' => 1], $ana)['status']); // enrollment 1
        same(201, $server->request('POST', '/api/enrollments', ['section_id' => 4], $ana)['status']); // enrollment 2
        $registrar = enrollmentLogin($server, 'registrar@example.test');
        same(200, $server->request('PATCH', '/api/registrar/enrollments/1', ['status' => 'approved'], $registrar)['status']);
        same(200, $server->request('PATCH', '/api/registrar/enrollments/2', ['status' => 'approved'], $registrar)['status']);
        // OQ-3: moving section 4 onto section 1's time clashes ana's approved classes. Nothing is written.
        $before = serverEnrollmentSnapshot($server); // mock: store bytes; C3/C4: row-level snapshot of the enrollment tables
        $patch = $server->request('PATCH', '/api/registrar/sections/4', ['meetings' => [['day' => 'MON', 'start' => '10:00', 'end' => '11:00', 'room' => 'R']]], $registrar);
        same([409, ['meetings']], [$patch['status'], array_keys($patch['json']['errors'])]);
        same($before, serverEnrollmentSnapshot($server));
        same(200, $server->request('PATCH', '/api/registrar/sections/4', ['meetings' => [['day' => 'WED', 'start' => '10:00', 'end' => '11:00', 'room' => 'R']]], $registrar)['status']);
        // Close the term: new requests and drops are refused, the registrar can still decide pending requests.
        $ben = enrollmentLogin($server, 'ben@example.test');
        same(201, $server->request('POST', '/api/enrollments', ['section_id' => 3], $ben)['status']); // enrollment 3, pending
        $registrar = enrollmentLogin($server, 'registrar@example.test');
        same(200, $server->request('PATCH', '/api/registrar/terms/1', ['enrollment_open' => false], $registrar)['status']);
        same(200, $server->request('PATCH', '/api/registrar/enrollments/3', ['status' => 'approved'], $registrar)['status']);
        $ana = enrollmentLogin($server, 'ana@example.test');
        same(409, $server->request('POST', '/api/enrollments', ['section_id' => 3], $ana)['status']);
        $drop = $server->request('POST', '/api/enrollments/1/drop', null, $ana);
        same([409, 'approved'], [$drop['status'], serverEnrollmentStatuses($server)[0]]);
        // The academic record shows the real counts.
        $summary = $server->request('GET', '/api/students/me/academic-record')['json']['data']['academic_record']['enrollment_summary'];
        same(['available' => true, 'counts' => ['pending' => 0, 'approved' => 2, 'rejected' => 0, 'dropped' => 0]], $summary);
        // Reopened: the drop works and frees the seat.
        $registrar = enrollmentLogin($server, 'registrar@example.test');
        $server->request('PATCH', '/api/registrar/terms/1', ['enrollment_open' => true], $registrar);
        $ana = enrollmentLogin($server, 'ana@example.test');
        same(200, $server->request('POST', '/api/enrollments/1/drop', null, $ana)['status']);
        same(0, $server->request('GET', '/api/sections/1')['json']['data']['section']['approved_count']);
    } finally { $server->close(); }
});
