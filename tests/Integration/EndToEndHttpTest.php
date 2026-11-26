<?php
declare(strict_types=1);

// T-39 end-to-end suite. UNVERIFIED: written without being executed (compromised host, owner forbids running tests).
// Decisions: docs/plans/T-22-T-32-DECISIONS.md section 3 (items 1-12) and section 5 (items 13-16).
// Item 17 (JSON -> MariaDB enrollment import round trip) lives in tests/Database/EnrollmentMySQLTest.php.
//
// HTTP part: real router and sessions over the existing AuthHttpServer harness, offline on mock repositories and, when
// MySQLTestSupport::enabled() and AIS_MYSQL_TEST_APP_PASSWORD are set, again on MariaDB with every module on mysql.
// Grade scale: APP_GRADE_SCALE is unset (default off) in the first runs, so grade entry over HTTP answers 409 grade_scale.
// The same scenario is run again with the server env APP_GRADE_SCALE=demo (owner-approved, demo-only scale, not the official
// university policy), where the full grading path runs over real HTTP (t39GradeDemoHttp), on the mock repositories and on MariaDB.
// The in-process test additionally covers the dual-hat separation-of-duties case through GradeService with GradeScale::demo().

use App\Core\Request;
use App\Faculty\Contracts\FacultySummary;
use App\Faculty\Controllers\GradeController;
use App\Faculty\Services\GradeScale;
use Database\Tools\MigrationRunner;

require_once dirname(__DIR__) . '/Faculty/GradeSupport.php';
require_once dirname(__DIR__) . '/Students/StudentSupport.php';
require_once dirname(__DIR__) . '/Database/MySQLTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/tools/MigrationRunner.php';

const T39_PASSWORD = 'Synthetic-test-pass-42';

/** Only the admin exists up front (id 1); everything else is created through the API. */
function t39AdminRow(): array
{
    return studentUserRows()[0];
}

function t39SectionInput(string $code, array $meetings, array $override = []): array
{
    return sectionInput(array_replace(['course_code' => $code, 'meetings' => $meetings], $override));
}

/** Logs in as $email, returns its CSRF token. */
function t39As(AuthHttpServer $server, ?string $email): ?string
{
    return enrollmentLogin($server, $email);
}

/**
 * Items 6-9, 15, 16 over real HTTP with APP_GRADE_SCALE=demo (demo-only scale, not the official policy). State on entry: section 1
 * (CS101, instructor faculty 1) has one approved student, ana (student 1); enrollments 1-4 exist; faculty2 is not assigned.
 * Enrollment 5 = ben in section 1 (approved late, before finalize). Enrollment 6 = the account of user 7 (profile created here as
 * student 3; approved late, after finalize). Ends with ana's grade 1.50 finalized, ben's INC, student 3's 5.00.
 * The dual-hat separation case (registrar who also teaches the section) is covered in-process below.
 */
function t39GradeDemoHttp(AuthHttpServer $s): void
{
    $data = static fn (array $r): array => $r['json']['data'];
    $statusOf = static function (array $r): array {
        $m = array_column($r['json']['data']['grades'], 'status', 'student_id');
        ksort($m);
        return $m;
    };
    $keys = static fn (array $r): array => array_keys($r['json']['errors']);
    $put = static fn (int $student, array $body, ?string $csrf): array => $s->request('PUT', "/api/faculty/classes/1/grades/{$student}", $body, $csrf);
    $post = static fn (string $path, ?array $body, ?string $csrf): array => $s->request('POST', $path, $body, $csrf);
    $staffView = static fn (): array => $s->request('GET', '/api/registrar/sections/1/grades');
    $anaGrade = static function () use ($s, $data): array {
        foreach ($data($s->request('GET', '/api/registrar/sections/1/grades'))['grades'] as $g) {
            if ($g['student_id'] === 1) { return $g; }
        }
        throw new RuntimeException('ana has no grade row.');
    };

    // unassigned faculty is refused on entry and submit
    $fac2 = t39As($s, 'faculty2@example.test');
    same(403, $put(1, ['value' => '2.00'], $fac2)['status'], 'unassigned faculty cannot enter');
    same(403, $post('/api/faculty/classes/1/grades/submit', null, $fac2)['status'], 'unassigned faculty cannot submit');

    // item 6: faculty enters; codes off the demo scale are 422 and write nothing; drafts can be updated; submit
    $fac = t39As($s, 'faculty@example.test');
    foreach (['3.25', 'inc'] as $bad) {
        $r = $put(1, ['value' => $bad], $fac);
        same([422, ['value']], [$r['status'], $keys($r)], "invalid code {$bad}");
    }
    $list = $s->request('GET', '/api/faculty/classes/1/grades');
    same([200, [], [1]], [$list['status'], $data($list)['grades'], $data($list)['missing']], 'refused codes wrote nothing');
    same(409, $post('/api/faculty/classes/1/grades/submit', null, $fac)['status'], 'submit needs a grade for every approved student');
    $r = $put(1, ['value' => '2.00', 'remarks' => 'staff only note'], $fac);
    same([201, '2.00'], [$r['status'], $data($r)['grade']['value']], 'enter 2.00');
    $r = $put(1, ['value' => '1.25', 'remarks' => 'staff only note'], $fac);
    same([200, '1.25'], [$r['status'], $data($r)['grade']['value']], 'update the draft');
    $r = $post('/api/faculty/classes/1/grades/submit', null, $fac);
    same([200, [1 => 'submitted']], [$r['status'], $statusOf($r)], 'submit');
    $r = $put(1, ['value' => '2.00'], $fac);
    same([409, ['status']], [$r['status'], $keys($r)], 'a submitted grade cannot be edited');

    // item 7: faculty and students cannot finalize, return or correct
    same(403, $post('/api/registrar/sections/1/grades/finalize', null, $fac)['status'], 'faculty cannot finalize');
    same(403, $post('/api/registrar/sections/1/grades/return', ['reason' => 'x'], $fac)['status'], 'faculty cannot return');
    $ana = t39As($s, 'ana@example.test');
    same(403, $post('/api/registrar/sections/1/grades/finalize', null, $ana)['status'], 'student cannot finalize');

    // item 15: return needs a reason; a returned draft cannot be finalized; faculty resubmits
    $reg = t39As($s, 'registrar@example.test');
    $r = $post('/api/registrar/sections/1/grades/return', null, $reg);
    same([422, ['reason']], [$r['status'], $keys($r)], 'return needs a reason');
    $r = $post('/api/registrar/sections/1/grades/return', ['reason' => 'Recheck exam 2'], $reg);
    same([200, [1 => 'draft']], [$r['status'], $statusOf($r)], 'return to draft');
    $r = $post('/api/registrar/sections/1/grades/finalize', null, $reg);
    same([409, [1]], [$r['status'], $r['json']['errors']['grades_missing'] ?? null], 'a returned draft is not submitted');
    same(404, $post('/api/registrar/sections/99/grades/finalize', null, $reg)['status'], 'unknown section');
    $fac = t39As($s, 'faculty@example.test');
    same(200, $post('/api/faculty/classes/1/grades/submit', null, $fac)['status'], 'resubmit');

    // item 16 (before finalization): ben is approved late; finalize is blocked until he is graded and submitted
    $ben = t39As($s, 'ben@example.test');
    same(201, $post('/api/enrollments', ['section_id' => 1], $ben)['status'], 'ben requests CS101 (enrollment 5)');
    $reg = t39As($s, 'registrar@example.test');
    same(200, $s->request('PATCH', '/api/registrar/enrollments/5', ['status' => 'approved'], $reg)['status']);
    $r = $post('/api/registrar/sections/1/grades/finalize', null, $reg);
    same([409, [2]], [$r['status'], $r['json']['errors']['grades_missing'] ?? null], 'finalize blocked by the late student');
    same([2], $data($staffView())['missing']);
    $fac = t39As($s, 'faculty@example.test');
    same(201, $put(2, ['value' => 'INC', 'remarks' => 'staff only note'], $fac)['status'], 'enter INC');
    same(200, $post('/api/faculty/classes/1/grades/submit', null, $fac)['status']);

    // finalize by the registrar (not the instructor); a finalized grade can no longer be entered
    $reg = t39As($s, 'registrar@example.test');
    $r = $post('/api/registrar/sections/1/grades/finalize', null, $reg);
    same([200, [1 => 'finalized', 2 => 'finalized'], []], [$r['status'], $statusOf($r), $data($r)['missing']], 'finalize');
    $fac = t39As($s, 'faculty@example.test');
    $r = $put(1, ['value' => '5.00'], $fac);
    same([409, ['status']], [$r['status'], $keys($r)], 'a finalized grade cannot be edited');

    // item 8: the student sees value, grade status and enrollment status; never remarks
    $ana = t39As($s, 'ana@example.test');
    $mine = $s->request('GET', '/api/grades/me');
    same([200, ['1.25', 'finalized', 'approved']], [$mine['status'], [$data($mine)['grades'][0]['value'], $data($mine)['grades'][0]['grade_status'], $data($mine)['grades'][0]['enrollment_status']]]);
    same(1, count($data($mine)['grades']));
    check(!str_contains($mine['body'], 'remarks') && !str_contains($mine['body'], 'staff only note'), 'Student response leaks staff data.');

    // item 9: a correction needs a reason, the latest seq and a demo code; refusals write nothing
    $reg = t39As($s, 'registrar@example.test');
    $grade = $anaGrade();
    $correct = static fn (array $body) => $s->request('POST', "/api/registrar/grades/{$grade['id']}/corrections", $body, $reg);
    $r = $correct(['value' => '1.50', 'reason' => 'Regrade']);
    same([422, ['expected_seq']], [$r['status'], $keys($r)], 'expected_seq is required');
    $r = $correct(['value' => '1.50', 'expected_seq' => $grade['seq']]);
    same([422, ['reason']], [$r['status'], $keys($r)], 'reason is required');
    $r = $correct(['value' => '1.50', 'reason' => 'Regrade', 'expected_seq' => $grade['seq'] - 1]);
    same([409, ['stale_grade']], [$r['status'], $keys($r)], 'stale expected_seq');
    same($grade, $anaGrade(), 'refused corrections wrote nothing');
    $r = $correct(['value' => '1.50', 'reason' => 'Regrade', 'expected_seq' => $grade['seq']]);
    same([200, '1.50', 'finalized'], [$r['status'], $data($r)['grade']['value'], $data($r)['grade']['status']], 'correction');
    $r = $correct(['value' => '2.00', 'reason' => 'Again', 'expected_seq' => $grade['seq']]);
    same([409, ['stale_grade']], [$r['status'], $keys($r)], 'the old seq is stale after a correction');
    $r = $correct(['value' => '3.25', 'reason' => 'Bad code', 'expected_seq' => $anaGrade()['seq']]);
    same([422, ['value']], [$r['status'], $keys($r)], 'correction with a code off the demo scale');
    same('1.50', $anaGrade()['value']);

    // item 16 (after finalization): the account of user 7 gets a profile (student 3) and is approved late; only that grade is finalized
    same(201, $s->request('POST', '/api/students', anaInput(['user_id' => 7, 'student_number' => '2026-00003', 'first_name' => 'Cara', 'last_name' => 'Dizon']), $reg)['status']);
    $cara = t39As($s, 'noprofile@example.test');
    same(201, $post('/api/enrollments', ['section_id' => 1], $cara)['status'], 'enrollment 6');
    $reg = t39As($s, 'registrar@example.test');
    same(200, $s->request('PATCH', '/api/registrar/enrollments/6', ['status' => 'approved'], $reg)['status']);
    $view = $staffView();
    same([[1 => 'finalized', 2 => 'finalized'], [3]], [$statusOf($view), $data($view)['missing']], 'late student shows as missing');
    $fac = t39As($s, 'faculty@example.test');
    same(201, $put(3, ['value' => '5.00'], $fac)['status']);
    same(200, $post('/api/faculty/classes/1/grades/submit', null, $fac)['status']);
    $reg = t39As($s, 'registrar@example.test');
    $r = $post('/api/registrar/sections/1/grades/finalize', null, $reg);
    same([200, [1 => 'finalized', 2 => 'finalized', 3 => 'finalized'], []], [$r['status'], $statusOf($r), $data($r)['missing']]);
    t39As($s, 'noprofile@example.test');
    $r = $s->request('GET', '/api/grades/me');
    same(['5.00', 'finalized', 'approved'], [$data($r)['grades'][0]['value'], $data($r)['grades'][0]['grade_status'], $data($r)['grades'][0]['enrollment_status']]);
}

/**
 * The whole HTTP scenario. User ids by creation order: 2 registrar, 3 faculty, 4 ana, 5 ben, 6 faculty2, 7 noprofile.
 * Student ids: ana 1, ben 2. Faculty ids: faculty 1 (user 3), faculty2 2 (user 6).
 * Terms: 1 open and current, 2 closed. Sections: 1 CS101 MON 09:00-10:30, 2 CS102 MON 10:00-11:00, 3 CS103 WED 09:00-10:00 (capacity 1),
 * 4 CS104 TUE 09:00-10:00, 5 CS201 in the closed term.
 */
function t39Http(AuthHttpServer $s, bool $mock, bool $demo = false): void
{
    $anonymous = '{"success":false,"message":"Authentication required.","errors":{}}';
    $denied = '{"success":false,"message":"Access denied.","errors":{}}';
    $json = static fn (array $r): array => $r['json']['data'];

    // --- item 1: admin creates the accounts -------------------------------------------------------------------------------------
    $admin = t39As($s, 'admin@example.test');
    foreach ([['registrar@example.test', ['registrar']], ['faculty@example.test', ['faculty']], ['ana@example.test', ['student']],
              ['ben@example.test', ['student']], ['faculty2@example.test', ['faculty']], ['noprofile@example.test', ['student']]] as [$email, $roles]) {
        same(201, $s->request('POST', '/api/admin/users', ['email' => $email, 'password' => T39_PASSWORD, 'roles' => $roles], $admin)['status'], "item 1 create {$email}");
    }

    // --- item 2: registrar creates profiles, term, sections and assigns the instructor ------------------------------------------------
    $reg = t39As($s, 'registrar@example.test');
    foreach ([['/api/students', anaInput()],
              ['/api/students', anaInput(['user_id' => 5, 'student_number' => '2026-00002', 'first_name' => 'Ben', 'last_name' => 'Cruz'])],
              ['/api/registrar/faculty', facultyInput()],
              ['/api/registrar/faculty', facultyInput(['user_id' => 6, 'employee_number' => 'EMP-002', 'first_name' => 'Cara', 'last_name' => 'Lim'])],
              ['/api/registrar/terms', termInput(['is_current' => true, 'enrollment_open' => true])],
              ['/api/registrar/terms', termInput(['code' => '2027-1S', 'name' => 'First Semester 2027-2028', 'start_date' => '2027-08-10', 'end_date' => '2027-12-18'])],
              ['/api/registrar/sections', t39SectionInput('CS101', [meeting('MON', '09:00', '10:30')])],
              ['/api/registrar/sections', t39SectionInput('CS102', [meeting('MON', '10:00', '11:00')])],
              ['/api/registrar/sections', t39SectionInput('CS103', [meeting('WED', '09:00', '10:00')], ['capacity' => 1])],
              ['/api/registrar/sections', t39SectionInput('CS104', [meeting('TUE', '09:00', '10:00')])],
              ['/api/registrar/sections', t39SectionInput('CS201', [meeting('MON', '09:00', '10:00')], ['term_id' => 2])],
              ['/api/registrar/sections/1/instructors', ['faculty_id' => 1]]] as [$path, $body]) {
        same(201, $s->request('POST', $path, $body, $reg)['status'], "item 2 POST {$path}");
    }

    // --- item 3: student requests; duplicate, clash, closed term and capacity are refused ---------------------------------------------
    $ana = t39As($s, 'ana@example.test');
    same(201, $s->request('POST', '/api/enrollments', ['section_id' => 1], $ana)['status']);        // enrollment 1
    same(409, $s->request('POST', '/api/enrollments', ['section_id' => 1], $ana)['status'], 'duplicate');
    same(409, $s->request('POST', '/api/enrollments', ['section_id' => 2], $ana)['status'], 'schedule clash with the pending CS101');
    same(409, $s->request('POST', '/api/enrollments', ['section_id' => 5], $ana)['status'], 'closed term');
    same(201, $s->request('POST', '/api/enrollments', ['section_id' => 4], $ana)['status']);        // enrollment 2
    $ben = t39As($s, 'ben@example.test');
    same(201, $s->request('POST', '/api/enrollments', ['section_id' => 3], $ben)['status']);        // enrollment 3
    same(201, $s->request('POST', '/api/enrollments', ['section_id' => 2], $ben)['status']);        // enrollment 4
    same(404, $s->request('POST', '/api/enrollments/1/drop', null, $ben)['status'], 'wrong owner: ben cannot drop ana\'s enrollment');
    $benIds = array_column($json($s->request('GET', '/api/enrollments/me'))['enrollments'], 'id');
    sort($benIds);
    same([3, 4], $benIds, 'ben sees only his own enrollments');

    // --- item 4: registrar approves, and rejects only with a reason; capacity then refuses --------------------------------------------
    $reg = t39As($s, 'registrar@example.test');
    same(200, $s->request('PATCH', '/api/registrar/enrollments/3', ['status' => 'approved'], $reg)['status']);   // section 3 is now full
    $noReason = $s->request('PATCH', '/api/registrar/enrollments/4', ['status' => 'rejected'], $reg);
    same([422, ['reason']], [$noReason['status'], array_keys($noReason['json']['errors'])], 'rejection needs a reason');
    $rejected = $s->request('PATCH', '/api/registrar/enrollments/4', ['status' => 'rejected', 'reason' => 'Missing documents'], $reg);
    same([200, 'rejected', 'Missing documents'], [$rejected['status'], $json($rejected)['enrollment']['status'], $json($rejected)['enrollment']['reason']]);
    same(404, $s->request('PATCH', '/api/registrar/enrollments/999', ['status' => 'approved'], $reg)['status']);
    same(200, $s->request('PATCH', '/api/registrar/enrollments/1', ['status' => 'approved'], $reg)['status']);
    same(200, $s->request('PATCH', '/api/registrar/enrollments/2', ['status' => 'approved'], $reg)['status']);
    $ana = t39As($s, 'ana@example.test');
    same(409, $s->request('POST', '/api/enrollments', ['section_id' => 3], $ana)['status'], 'capacity: section 3 is full');
    $noProfile = t39As($s, 'noprofile@example.test');
    same(403, $s->request('POST', '/api/enrollments', ['section_id' => 1], $noProfile)['status'], 'student without a profile');

    // --- item 13: a schedule PATCH that clashes with approved classes is refused (OQ-3); a harmless one is accepted ------------------
    $reg = t39As($s, 'registrar@example.test');
    $before = $s->request('GET', '/api/sections/4')['body'];
    $clash = $s->request('PATCH', '/api/registrar/sections/4', ['meetings' => [['day' => 'MON', 'start' => '10:00', 'end' => '11:00', 'room' => 'R-1']]], $reg);
    same([409, ['meetings']], [$clash['status'], array_keys($clash['json']['errors'])]);
    same($before, $s->request('GET', '/api/sections/4')['body'], 'nothing written on a refused schedule PATCH');
    same(200, $s->request('PATCH', '/api/registrar/sections/4', ['meetings' => [['day' => 'WED', 'start' => '10:00', 'end' => '11:00', 'room' => 'R-1']]], $reg)['status']);

    // --- item 14: a student cannot drop after the term closes (N-1); reopening lets the drop through -----------------------------------
    same(200, $s->request('PATCH', '/api/registrar/terms/1', ['enrollment_open' => false], $reg)['status']);
    $ana = t39As($s, 'ana@example.test');
    same(409, $s->request('POST', '/api/enrollments/2/drop', null, $ana)['status'], 'drop after the term closed');
    same(409, $s->request('POST', '/api/enrollments', ['section_id' => 3], $ana)['status'], 'new request after the term closed');
    $reg = t39As($s, 'registrar@example.test');
    same(200, $s->request('PATCH', '/api/registrar/terms/1', ['enrollment_open' => true], $reg)['status']);

    // --- item 5: faculty sees own classes and roster (T-31); an unassigned faculty member gets 403 -------------------------------------
    $fac = t39As($s, 'faculty@example.test');
    same([1], array_column($json($s->request('GET', '/api/faculty/classes'))['classes'], 'section_id'));
    $roster = $s->request('GET', '/api/faculty/classes/1/students');
    same([200, [1]], [$roster['status'], array_column($json($roster)['students'], 'student_id')], 'roster lists the approved student only');
    $fac2 = t39As($s, 'faculty2@example.test');
    same([], array_column($json($s->request('GET', '/api/faculty/classes'))['classes'], 'section_id'));
    same(403, $s->request('GET', '/api/faculty/classes/1/students')['status'], 'unassigned faculty roster');
    same(403, $s->request('GET', '/api/faculty/classes/1/grades')['status'], 'unassigned faculty grades');
    same(403, $s->request('GET', '/api/faculty/classes/99/students')['status'], 'unknown section is 403 too');
    t39As($s, 'ana@example.test');
    same(403, $s->request('GET', '/api/faculty/classes/1/students')['status'], 'student roster');
    t39As($s, 'registrar@example.test');
    same(403, $s->request('GET', '/api/faculty/classes/1/students')['status'], 'registrar roster');

    // --- items 6-9, 15, 16 (demo scale): the whole grading path over HTTP; otherwise items 6, 7 on the default scale ---------------------
    if ($demo) {
        t39GradeDemoHttp($s);
    } else {
        // APP_GRADE_SCALE unset (default off): the grade path fails closed.
        $fac = t39As($s, 'faculty@example.test');
        $put = $s->request('PUT', '/api/faculty/classes/1/grades/1', ['value' => '1.00'], $fac);
        same([409, ['grade_scale' => 'Grade entry is not available yet.']], [$put['status'], $put['json']['errors']], 'item 6 grade_scale');
        $list = $s->request('GET', '/api/faculty/classes/1/grades');
        same([200, []], [$list['status'], $json($list)['grades']], 'nothing was written');
        same(409, $s->request('POST', '/api/faculty/classes/1/grades/submit', null, $fac)['status'], 'submit needs a grade for every approved student');
        if ($mock) {
            check(!file_exists($s->directory . '/faculty/grades.json'), 'No grade store may be written.');
        }
        $reg = t39As($s, 'registrar@example.test');
        same(409, $s->request('POST', '/api/registrar/sections/1/grades/finalize', null, $reg)['status'], 'item 7 finalize with no submitted grades');
        same(409, $s->request('POST', '/api/registrar/sections/1/grades/return', ['reason' => 'x'], $reg)['status'], 'nothing submitted to return');
        $correction = $s->request('POST', '/api/registrar/grades/1/corrections', ['value' => '1.00', 'reason' => 'x', 'expected_seq' => 1], $reg);
        same([409, ['grade_scale' => 'Grade entry is not available yet.']], [$correction['status'], $correction['json']['errors']]);
        same(404, $s->request('POST', '/api/registrar/sections/99/grades/finalize', null, $reg)['status']);
        same(200, $s->request('GET', '/api/registrar/sections/1/grades')['status']);
    }

    // --- item 8 (HTTP part): the student's grades; the academic record shows the real enrollment counts ------------------------------
    $ana = t39As($s, 'ana@example.test');
    $mine = $s->request('GET', '/api/grades/me');
    if ($demo) {
        same(200, $mine['status']);
        same([['1.50', 'finalized', 'approved']], array_map(static fn (array $g): array => [$g['value'], $g['grade_status'], $g['enrollment_status']], $json($mine)['grades']),
            'ana sees her one corrected finalized grade');
    } else {
        same([200, []], [$mine['status'], $json($mine)['grades']]);
    }
    $record = $json($s->request('GET', '/api/students/me/academic-record'))['academic_record'];
    same(['available' => true, 'counts' => ['pending' => 0, 'approved' => 2, 'rejected' => 0, 'dropped' => 0]], $record['enrollment_summary']);
    if ($demo) {
        same(true, $record['grades_summary']['available'], 'a finalized grade exists');
    } else {
        same(['available' => false], $record['grades_summary'], 'no finalized grade yet');
    }

    // --- item 10 (HTTP part): a drop works while the term is open and the enrollment summary follows -------------------------------
    same(200, $s->request('POST', '/api/enrollments/2/drop', null, $ana)['status']);
    $record = $json($s->request('GET', '/api/students/me/academic-record'))['academic_record'];
    same(['pending' => 0, 'approved' => 1, 'rejected' => 0, 'dropped' => 1], $record['enrollment_summary']['counts']);

    // --- item 11: admin reads the audit log (T-05) -------------------------------------------------------------------------------
    t39As($s, 'admin@example.test');
    $audit = $s->request('GET', '/api/admin/audit-logs?action=user.created&per_page=100');
    same(200, $audit['status']);
    same([1, 6, 6], [$json($audit)['pagination']['page'], $json($audit)['pagination']['total'], count($json($audit)['entries'])]);
    foreach (['password_hash', '$2y$', T39_PASSWORD] as $secret) {
        check(!str_contains($audit['body'], $secret), 'The audit log must not expose ' . $secret);
    }

    // --- item 12: negatives. Wrong role is 401 anonymous / 403 authenticated, on every T-22/T-30/T-31/T-32/T-05 route --------------
    $staff = ['registrar@example.test', 'admin@example.test'];
    $routes = [
        ['GET', '/api/admin/audit-logs', null, ['admin@example.test']],
        ['POST', '/api/enrollments', ['section_id' => 1], ['ana@example.test']],
        ['GET', '/api/enrollments/me', null, ['ana@example.test']],
        ['POST', '/api/enrollments/1/drop', null, ['ana@example.test']],
        ['GET', '/api/schedules/me', null, ['ana@example.test']],
        ['GET', '/api/registrar/enrollments', null, $staff],
        ['PATCH', '/api/registrar/enrollments/1', ['status' => 'approved'], $staff],
        ['GET', '/api/registrar/faculty', null, $staff],
        ['POST', '/api/registrar/faculty', facultyInput(), $staff],
        ['PATCH', '/api/registrar/faculty/1', ['status' => 'active'], $staff],
        ['GET', '/api/faculty/me', null, ['faculty@example.test']],
        ['GET', '/api/faculty/classes', null, ['faculty@example.test']],
        ['GET', '/api/faculty/classes/1/students', null, ['faculty@example.test']],
        ['GET', '/api/faculty/classes/1/grades', null, ['faculty@example.test']],
        ['PUT', '/api/faculty/classes/1/grades/1', ['value' => '1.00'], ['faculty@example.test']],
        ['POST', '/api/faculty/classes/1/grades/submit', null, ['faculty@example.test']],
        ['GET', '/api/registrar/sections/1/grades', null, $staff],
        ['POST', '/api/registrar/sections/1/grades/finalize', null, $staff],
        ['POST', '/api/registrar/sections/1/grades/return', ['reason' => 'x'], $staff],
        ['POST', '/api/registrar/grades/1/corrections', ['value' => '1.00', 'reason' => 'x', 'expected_seq' => 1], $staff],
        ['GET', '/api/grades/me', null, ['ana@example.test']],
    ];
    foreach ($routes as [$method, $path, $body, $allowed]) {
        foreach ([null, 'admin@example.test', 'registrar@example.test', 'faculty@example.test', 'ana@example.test'] as $email) {
            if ($email !== null && in_array($email, $allowed, true)) {
                continue;
            }
            $csrf = t39As($s, $email);
            $response = $s->request($method, $path, $body, $csrf);
            same([$email === null ? 401 : 403, $email === null ? $anonymous : $denied], [$response['status'], $response['body']], "{$method} {$path} as " . ($email ?? 'anonymous'));
        }
    }
    // Missing CSRF token on a write by the right role is 403.
    foreach ([['ana@example.test', 'POST', '/api/enrollments', ['section_id' => 1]],
              ['ana@example.test', 'POST', '/api/enrollments/1/drop', null],
              ['registrar@example.test', 'PATCH', '/api/registrar/enrollments/1', ['status' => 'approved']],
              ['registrar@example.test', 'POST', '/api/registrar/faculty', facultyInput(['user_id' => 7, 'employee_number' => 'EMP-007'])],
              ['faculty@example.test', 'PUT', '/api/faculty/classes/1/grades/1', ['value' => '1.00']],
              ['faculty@example.test', 'POST', '/api/faculty/classes/1/grades/submit', null],
              ['registrar@example.test', 'POST', '/api/registrar/sections/1/grades/finalize', null],
              ['registrar@example.test', 'POST', '/api/registrar/sections/1/grades/return', ['reason' => 'x']],
              ['registrar@example.test', 'POST', '/api/registrar/grades/1/corrections', ['value' => '1.00', 'reason' => 'x', 'expected_seq' => 1]],
              ['admin@example.test', 'POST', '/api/admin/users', ['email' => 'late@example.test', 'password' => T39_PASSWORD, 'roles' => ['student']]]] as [$email, $method, $path, $body]) {
        t39As($s, $email);
        $response = $s->request($method, $path, $body);
        same([403, 'Invalid CSRF token.'], [$response['status'], $response['json']['message']], "no CSRF: {$method} {$path}");
    }
    // The CSRF refusals above created nothing: still 6 created accounts.
    t39As($s, 'admin@example.test');
    same(6, $json($s->request('GET', '/api/admin/audit-logs?action=user.created&per_page=100'))['pagination']['total']);
}

test('T-39 HTTP (mock): items 1-8, 10-14 over the real router, grade path fails closed, negatives and CSRF', function (): void {
    $server = new AuthHttpServer([], 'public/router.php', [t39AdminRow()]);
    try {
        seedStudentRuntime($server->directory, false);
        t39Http($server, true);
    } finally { $server->close(); }
});

test('T-39 HTTP (mock, APP_GRADE_SCALE=demo): same scenario with the full grading path over HTTP', function (): void {
    $server = new AuthHttpServer(['APP_GRADE_SCALE' => 'demo'], 'public/router.php', [t39AdminRow()]);
    try {
        seedStudentRuntime($server->directory, false);
        t39Http($server, true, true);
    } finally { $server->close(); }
});

/** Fresh ais_test schema (all migrations, including the V0010 grade value checks) with only the admin account and the BSCS program. */
function t39MysqlServer(bool $demo = false): AuthHttpServer
{
    $owner = MySQLTestSupport::connect();
    MySQLTestSupport::reset($owner);
    (new MigrationRunner($owner, 'ais_test', dirname(__DIR__, 2) . '/database/migrations'))->up();
    $row = t39AdminRow();
    $owner->prepare('INSERT INTO users (id, email, status, password_hash, auth_version) VALUES (?, ?, ?, ?, ?)')
        ->execute([1, $row['email'], 'active', $row['password_hash'], 1]);
    $owner->exec("INSERT INTO user_roles (user_id, role) VALUES (1, 'admin')");
    $owner->exec("INSERT INTO programs (code, name, max_year_level) VALUES ('BSCS', 'BS Computer Science', 4), ('ASCT', 'Associate in Computer Technology', 2)");
    // C4-style: every module on MariaDB, including faculty and grades (grades needs students and enrollment; enrollment needs faculty).
    $environment = ['APP_ENV' => 'testing', 'APP_REPOSITORY' => 'mysql', 'DB_HOST' => getenv('DB_HOST'), 'DB_PORT' => getenv('DB_PORT'),
        'DB_DATABASE' => 'ais_test', 'DB_USERNAME' => 'ais_test_app', 'DB_PASSWORD' => getenv('AIS_MYSQL_TEST_APP_PASSWORD')];
    foreach (['AUTH', 'STUDENTS', 'ENROLLMENT', 'FACULTY', 'GRADES'] as $module) {
        $environment['APP_REPOSITORY_' . $module] = 'mysql';
    }
    if ($demo) {
        $environment['APP_GRADE_SCALE'] = 'demo';
    }
    return new AuthHttpServer($environment, 'public/router.php', [$row]);
}

if (MySQLTestSupport::enabled() && is_string(getenv('AIS_MYSQL_TEST_APP_PASSWORD'))) {
    test('T-39 HTTP (MariaDB, all modules on mysql): same scenario as the mock run', function (): void {
        $server = t39MysqlServer();
        try {
            t39Http($server, false);
        } finally { $server->close(); }
    });
    test('T-39 HTTP (MariaDB, all modules on mysql, APP_GRADE_SCALE=demo): full grading path over HTTP', function (): void {
        $server = t39MysqlServer(true);
        try {
            t39Http($server, false, true);
        } finally { $server->close(); }
    });
}

/** In-process: faculty is user 3 (faculty 10), registrar user 2, admin user 1, students ana/ben/cara = 1/2/3, section 1 CS101-A. */
test('T-39 grading path (in-process, GradeScale::demo()): enter, submit, return, resubmit, late approvals, finalize, correct, drop', function (): void {
    $dir = tempDirectory();
    try {
        $f = gradeFixture($dir, GradeScale::demo(), [1]);          // only ana is approved up front; every value used is a demo code
        $s = $f['service'];

        // enter and submit (item 6)
        gradeSubmitAll($f, [1]);
        same('submitted', $s->classGrades($f['faculty'], 1)['grades'][0]['status']);
        // item 15: return with a reason, finalize refuses the returned draft, faculty resubmits
        gradeFailure(fn () => $s->returnToDraft($f['registrar'], 1, []), 422, 'reason');
        same('draft', $s->returnToDraft($f['registrar'], 1, ['reason' => 'Recheck exam 2'])['grades'][0]['status']);
        gradeFailure(fn () => $s->finalize($f['registrar'], 1), 409, 'grades_missing');
        $s->submit($f['faculty'], 1);
        // item 16 (before finalization): ben is approved late, finalize is blocked until he is graded and submitted
        $ben = $f['enroll']->request($f['ben'], ['section_id' => 1])['id'];
        $f['enroll']->decide($f['registrar'], $ben, ['status' => 'approved']);
        same([2], gradeFailure(fn () => $s->finalize($f['registrar'], 1), 409, 'grades_missing')['grades_missing']);
        same([2], $s->sectionGrades($f['registrar'], 1)['missing']);
        $s->enter($f['faculty'], 1, 2, ['value' => '2.00', 'remarks' => 'staff only note']);
        $s->submit($f['faculty'], 1);
        // item 7: separation of duties. A registrar who also holds an instructor profile for the section is refused; faculty cannot finalize
        $f['directory']->byUser[2] = new FacultySummary(10, 2, 'EMP-010', 'Reyes, Ana', 'inactive');
        gradeFailure(fn () => $s->finalize($f['registrar'], 1), 403);
        unset($f['directory']->byUser[2]);
        gradeFailure(fn () => $s->finalize($f['faculty'], 1), 403);
        // the registrar who returned the grades may finalize them
        $final = $s->finalize($f['registrar'], 1);
        same(['finalized', 'finalized'], array_column($final['grades'], 'status'));
        same([], $final['missing']);
        gradeFailure(fn () => $s->enter($f['faculty'], 1, 1, ['value' => '5.00']), 409, 'status');

        // item 8: the student sees value, grade status and enrollment status; never remarks
        $controller = new GradeController($s);
        $mine = $controller->mine(new Request('GET', '/api/grades/me'), [], $f['ana']);
        same(200, $mine->status);
        same([['section_id' => 1, 'term_id' => 1, 'course_code' => 'CS101', 'section_code' => 'A', 'value' => '1.25',
            'grade_status' => 'finalized', 'enrollment_status' => 'approved']], json_decode($mine->body, true)['data']['grades']);
        same(['finalized' => 1, 'finalized_approved' => 1], $f['reader']->countsFor(1));          // academic-record grade summary source

        // item 9: correction needs a reason and the latest seq; stale and missing tokens write nothing
        $grade = $s->sectionGrades($f['registrar'], 1)['grades'][0];
        same(1, $grade['student_id']);
        $before = gradeStoreBytes($dir);
        gradeFailure(fn () => $s->correct($f['registrar'], $grade['id'], ['value' => '2.00', 'reason' => 'Regrade']), 422, 'expected_seq');
        gradeFailure(fn () => $s->correct($f['registrar'], $grade['id'], ['value' => '2.00', 'expected_seq' => $grade['seq']]), 422, 'reason');
        gradeFailure(fn () => $s->correct($f['registrar'], $grade['id'], ['value' => '2.00', 'reason' => 'Regrade', 'expected_seq' => $grade['seq'] - 1]), 409, 'stale_grade');
        same($before, gradeStoreBytes($dir));
        $corrected = $s->correct($f['registrar'], $grade['id'], ['value' => '2.00', 'reason' => 'Regrade', 'expected_seq' => $grade['seq']]);
        same(['2.00', 'finalized'], [$corrected['grade']['value'], $corrected['grade']['status']]);
        gradeFailure(fn () => $s->correct($f['registrar'], $grade['id'], ['value' => '5.00', 'reason' => 'Again', 'expected_seq' => $grade['seq']]), 409, 'stale_grade');
        $own = array_values(array_filter(gradeHistory($dir), static fn (array $h): bool => $h['student_id'] === 1));
        same(['entered', 'submitted', 'returned', 'submitted', 'finalized', 'corrected'], array_column($own, 'action'), 'history is complete for ana');
        $last = end($own);
        same(['1.25', '2.00', 'Regrade', 2], [$last['old_value'], $last['new_value'], $last['reason'], $last['actor_id']]);
        same(range(1, count(gradeHistory($dir))), array_column(gradeHistory($dir), 'seq'));

        // item 16 (after finalization): cara is approved late, shows as missing, is graded alone and only her grade is finalized
        $cara = $f['enroll']->request($f['cara'], ['section_id' => 1])['id'];
        $f['enroll']->decide($f['registrar'], $cara, ['status' => 'approved']);
        same([3], $s->sectionGrades($f['registrar'], 1)['missing']);
        $historyBefore = count(gradeHistory($dir));
        $s->enter($f['faculty'], 1, 3, ['value' => '5.00']);
        $s->submit($f['faculty'], 1);
        same([], $s->finalize($f['registrar'], 1)['missing']);
        $newRows = array_slice(gradeHistory($dir), $historyBefore);
        same([['entered', 3], ['submitted', 3], ['finalized', 3]], array_map(static fn (array $h): array => [$h['action'], $h['student_id']], $newRows));

        // item 10: a finalized grade survives a later drop and shows the enrollment status
        $f['enroll']->drop($f['ana'], $f['enrollments'][1]);
        $after = $controller->mine(new Request('GET', '/api/grades/me'), [], $f['ana']);
        $row = json_decode($after->body, true)['data']['grades'][0];
        same(['2.00', 'finalized', 'dropped'], [$row['value'], $row['grade_status'], $row['enrollment_status']]);
        same(['finalized' => 1, 'finalized_dropped' => 1], $f['reader']->countsFor(1));
        same('finalized', $s->sectionGrades($f['registrar'], 1)['grades'][0]['status']);

        // remarks stay staff-only: never in any student response, present in the staff view
        foreach ([$mine->body, $after->body, json_encode($s->mine($f['ben'], [])), json_encode(['counts' => $f['reader']->countsFor(1)])] as $body) {
            check(!str_contains($body, 'remarks') && !str_contains($body, 'staff only note') && !str_contains($body, 'Regrade'), 'Student response leaks staff data.');
        }
        check(str_contains($controller->classGrades(new Request('GET', '/x'), ['id' => 1], $f['faculty'])->body, 'staff only note'), 'Staff view keeps remarks.');
        // a non-assigned faculty member is refused
        $f['directory']->byUser[3] = new FacultySummary(11, 3, 'EMP-011', 'Cruz, Ben', 'active');
        gradeFailure(fn () => $s->classGrades($f['faculty'], 1), 403);
        gradeFailure(fn () => $s->enter($f['faculty'], 1, 1, ['value' => '1.00']), 403);
    } finally { removeTestDirectory($dir); }
});
