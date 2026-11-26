<?php
declare(strict_types=1);

require_once __DIR__ . '/FacultySupport.php';
require_once dirname(__DIR__) . '/Students/StudentSupport.php';

test('Faculty student visibility HTTP: GET /api/students/{id} follows current assignment and approval, summary fields only', function (): void {
    $server = new AuthHttpServer([], 'public/router.php', studentUserRows());
    try {
        seedStudentRuntime($server->directory, false);
        $as = static fn (string $email): ?string => enrollmentLogin($server, $email);
        $registrar = $as('registrar@example.test');
        foreach ([['/api/students', anaInput()], ['/api/students', anaInput(['user_id' => 5, 'student_number' => '2026-00002', 'first_name' => 'Ben'])],
                  ['/api/registrar/terms', termInput(['is_current' => true, 'enrollment_open' => true])],
                  ['/api/registrar/sections', sectionInput()],
                  ['/api/registrar/faculty', facultyInput()],                                  // faculty 1 = user 3
                  ['/api/registrar/faculty', facultyInput(['user_id' => 8, 'employee_number' => 'EMP-008'])]] as [$path, $body]) {
            same(201, $server->request('POST', $path, $body, $registrar)['status']);
        }
        same(201, $server->request('POST', '/api/registrar/sections/1/instructors', ['faculty_id' => 1], $registrar)['status']);
        $notFound = '{"success":false,"message":"Student not found.","errors":{}}';
        $seen = static function (string $email, int $id) use ($as, $server): array {
            $as($email);
            $r = $server->request('GET', "/api/students/{$id}");
            return [$r['status'], $r['body'], $r['json']['data']['student'] ?? null];
        };
        $status = static fn (string $email, int $id): int => $seen($email, $id)[0];

        $ana = $as('ana@example.test');
        same(201, $server->request('POST', '/api/enrollments', ['section_id' => 1], $ana)['status']);
        $ben = $as('ben@example.test');
        same(201, $server->request('POST', '/api/enrollments', ['section_id' => 1], $ben)['status']);
        // Pending: not visible.
        same([404, $notFound], array_slice($seen('faculty@example.test', 1), 0, 2));

        $registrar = $as('registrar@example.test');
        same(200, $server->request('PATCH', '/api/registrar/enrollments/1', ['status' => 'approved'], $registrar)['status']);
        same(200, $server->request('PATCH', '/api/registrar/enrollments/2', ['status' => 'rejected', 'reason' => 'Missing documents'], $registrar)['status']);

        // Assigned faculty: approved student only, summary fields only.
        [$code, $body, $student] = $seen('faculty@example.test', 1);
        same(200, $code);
        same(['id', 'student_number', 'full_name', 'program_code', 'year_level', 'sections'], array_keys($student));
        same([['section_id' => 1, 'section_code' => 'CS101-A', 'enrollment_status' => 'approved']], $student['sections']);
        same('Reyes, Ana', $student['full_name']);
        foreach (['user_id', 'academic_status', 'admission_year', 'middle_name', 'created_at'] as $private) {
            check(!str_contains($body, $private), "faculty view leaks {$private}");
        }
        same([404, $notFound], array_slice($seen('faculty@example.test', 2), 0, 2)); // rejected
        same([404, $notFound], array_slice($seen('faculty@example.test', 999), 0, 2));
        same([404, $notFound], array_slice($seen('faculty2@example.test', 1), 0, 2)); // not assigned
        same(200, $status('registrar@example.test', 1));

        // Read-only: faculty writes stay forbidden.
        $csrf = $as('faculty@example.test');
        same([403, 403, 403], [$server->request('PATCH', '/api/students/1', ['year_level' => 3], $csrf)['status'],
            $server->request('POST', '/api/students', anaInput(), $csrf)['status'], $server->request('GET', '/api/students')['status']]);

        // Unassigned after the fact, then reassigned, then profile inactive.
        $registrar = $as('registrar@example.test');
        same(200, $server->request('DELETE', '/api/registrar/sections/1/instructors/1', null, $registrar)['status']);
        same(404, $status('faculty@example.test', 1));
        $registrar = $as('registrar@example.test');
        same(201, $server->request('POST', '/api/registrar/sections/1/instructors', ['faculty_id' => 1], $registrar)['status']);
        same(200, $status('faculty@example.test', 1));
        $registrar = $as('registrar@example.test');
        same(200, $server->request('PATCH', '/api/registrar/faculty/1', ['status' => 'inactive'], $registrar)['status']);
        same(404, $status('faculty@example.test', 1));
        $registrar = $as('registrar@example.test');
        same(200, $server->request('PATCH', '/api/registrar/faculty/1', ['status' => 'active'], $registrar)['status']);
        same(200, $status('faculty@example.test', 1));

        // Current term only: another term becoming current hides the student; switching back restores it; no current term denies.
        $registrar = $as('registrar@example.test');
        same(201, $server->request('POST', '/api/registrar/terms', termInput(['code' => '2027-1S', 'name' => 'First Semester 2027-2028',
            'start_date' => '2027-08-10', 'end_date' => '2027-12-18', 'is_current' => true]), $registrar)['status']);
        same(404, $status('faculty@example.test', 1));
        $registrar = $as('registrar@example.test');
        same(200, $server->request('PATCH', '/api/registrar/terms/2', ['is_current' => false], $registrar)['status']);
        same(404, $status('faculty@example.test', 1));   // no current term at all
        $registrar = $as('registrar@example.test');
        same(200, $server->request('PATCH', '/api/registrar/terms/1', ['is_current' => true], $registrar)['status']);
        same(200, $status('faculty@example.test', 1));

        // Dropped after approval: no longer visible.
        $ana = $as('ana@example.test');
        same(200, $server->request('POST', '/api/enrollments/1/drop', null, $ana)['status']);
        same(404, $status('faculty@example.test', 1));
    } finally { $server->close(); }
});
