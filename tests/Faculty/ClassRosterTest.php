<?php
declare(strict_types=1);

use App\Auth\Services\AuthorizationService;
use App\Enrollment\Contracts\SectionDirectory;
use App\Enrollment\Contracts\SectionSummary;
use App\Faculty\Services\ClassRosterService;
use App\Students\Contracts\StudentDirectory;
use App\Students\Contracts\StudentSummary;

require_once __DIR__ . '/FacultySupport.php';

/** Double: section 10 is taught by faculty 2 (user 3) and has approved students 5, 3, 4, 9 (9 has no summary). */
function rosterSections(): SectionDirectory
{
    return new class implements SectionDirectory {
        public function findSection(int $sectionId): ?SectionSummary { return null; }
        public function approvedStudentIds(int $sectionId): array { return $sectionId === 10 ? [5, 3, 4, 9] : [7]; }
        public function isAssigned(int $facultyId, int $sectionId): bool { return $facultyId === 2 && $sectionId === 10; }
        public function sectionIdsForFaculty(int $facultyId): array { return []; }
        public function assignedFacultyIds(int $sectionId): array { return []; }
    };
}

/** Double: student 7 exists but is not approved in section 10; it must never appear. */
function rosterStudents(): StudentDirectory
{
    return new class implements StudentDirectory {
        public function findById(int $studentId): ?StudentSummary
        {
            $rows = [3 => ['2026-0003', 'Cruz, Ben'], 4 => ['2026-0004', 'abad, Zed'], 5 => ['2026-0005', 'Cruz, Anna'], 7 => ['2026-0007', 'Lim, Cara']];
            return isset($rows[$studentId]) ? new StudentSummary($studentId, 100 + $studentId, $rows[$studentId][0], $rows[$studentId][1], 'BSCS', 2, 'active') : null;
        }
        public function findByUserId(int $userId): ?StudentSummary { return null; }
        public function isEnrollmentEligible(int $studentId): bool { return true; }
    };
}

function rosterService(array $f): ClassRosterService
{
    return new ClassRosterService($f['directory'], rosterSections(), rosterStudents(), new AuthorizationService());
}

test('Class roster service: approved only, stable order, summary fields only, missing summary omitted', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        facultyProfiles($f);
        $out = rosterService($f)->roster($f['users']->findById(3), 10, []);
        same([4, 5, 3], array_column($out['students'], 'student_id')); // abad, Cruz Anna, Cruz Ben; 7 and 9 absent
        same(['student_id', 'student_number', 'full_name', 'program_code', 'year_level'], array_keys($out['students'][0]));
        same(['page' => 1, 'per_page' => 20, 'total' => 3], $out['pagination']);
    } finally { removeTestDirectory($dir); }
});

test('Class roster service: search by name and number, case-insensitive; pagination', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        facultyProfiles($f);
        $actor = $f['users']->findById(3);
        $ids = static fn (array $q): array => array_column(rosterService($f)->roster($actor, 10, $q)['students'], 'student_id');
        same([5, 3], $ids(['search' => 'CRUZ']));
        same([4], $ids(['search' => '2026-0004']));
        same([], $ids(['search' => 'nobody']));
        same([5], $ids(['page' => '2', 'per_page' => '1']));
        $page = rosterService($f)->roster($actor, 10, ['page' => '3', 'per_page' => '2']);
        same([[], 3], [$page['students'], $page['pagination']['total']]);
    } finally { removeTestDirectory($dir); }
});

test('Class roster service: unassigned or unknown section 403, no profile 404, inactive 403, bad query 422', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        facultyProfiles($f);
        $svc = rosterService($f);
        $own = $f['users']->findById(3);
        same(403, httpFailure(fn () => $svc->roster($own, 11, []))->status);
        same(403, httpFailure(fn () => $svc->roster($own, 999, []))->status);
        same(403, httpFailure(fn () => $svc->roster($f['users']->findById(6), 10, []))->status); // faculty 1, not assigned
        same(403, httpFailure(fn () => $svc->roster($f['users']->findById(4), 10, []))->status); // student role
        same(404, httpFailure(fn () => $svc->roster($f['users']->findById(8), 10, []))->status); // no profile
        foreach ([['search' => ['a']], ['search' => str_repeat('a', 101)], ['page' => '0'], ['page' => ['1']], ['per_page' => '101']] as $query) {
            same(422, httpFailure(fn () => $svc->roster($own, 10, $query))->status);
        }
        $f['service']->update($f['users']->findById(2), 2, ['status' => 'inactive']);
        same(403, httpFailure(fn () => $svc->roster($own, 10, []))->status);
    } finally { removeTestDirectory($dir); }
});

test('Class roster HTTP: role matrix, 403 for unassigned or unknown, empty roster for assigned section (mock approved list is empty until T-22)', function (): void {
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
        same(201, $server->request('POST', '/api/registrar/faculty', facultyInput(), $registrar)['status']); // user 3 -> faculty 1
        same(201, $server->request('POST', '/api/registrar/sections/1/instructors', ['faculty_id' => 1], $registrar)['status']);
        $denied = '{"success":false,"message":"Access denied.","errors":{}}';
        $path = '/api/faculty/classes/1/students';
        foreach ([[null, 401], ['admin@example.test', 403], ['registrar@example.test', 403], ['ana@example.test', 403], ['faculty3@example.test', 404]] as [$email, $status]) {
            enrollmentLogin($server, $email); // faculty3 has the role but no profile
            same($status, $server->request('GET', $path)['status']);
        }
        $csrf = enrollmentLogin($server, 'faculty@example.test');
        $ok = $server->request('GET', $path);
        same([200, [], ['page' => 1, 'per_page' => 20, 'total' => 0]], [$ok['status'], $ok['json']['data']['students'], $ok['json']['data']['pagination']]);
        foreach (['/api/faculty/classes/2/students', '/api/faculty/classes/99/students'] as $other) {
            $r = $server->request('GET', $other);
            same([403, $denied], [$r['status'], $r['body']]);
        }
        same(404, $server->request('GET', '/api/faculty/classes/abc/students')['status']);
        same(422, $server->request('GET', $path . '?per_page=0')['status']);
        same(422, $server->request('GET', $path . '?search[]=a')['status']);
        same(405, $server->request('POST', $path, [], $csrf)['status']);
    } finally { $server->close(); }
});
