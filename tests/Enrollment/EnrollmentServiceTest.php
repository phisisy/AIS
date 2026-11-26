<?php
declare(strict_types=1);

use App\Enrollment\Contracts\UnavailableFacultyIdentity;
use App\Enrollment\Services\EnrollmentSummarySource;
use App\Enrollment\Services\RepositorySectionDirectory;

require_once __DIR__ . '/EnrollmentSupport.php';

test('Enrollment service: a request is pending, comes from the session student and writes one history row', function (): void {
    $dir = tempDirectory();
    try {
        [$service, , $users] = requestFixture($dir);
        [$ana] = t22Actors($users);
        $row = $service->request($ana, ['section_id' => 1]);
        same(['id' => 1, 'student_id' => 1, 'section_id' => 1, 'term_id' => 1, 'status' => 'pending', 'requested_at' => t22Now(),
            'decided_at' => null, 'dropped_at' => null, 'reason' => null], $row);
        same([['enrollment_id' => 1, 'seq' => 1, 'at' => t22Now(), 'action' => 'requested', 'actor_id' => 4, 'reason' => null]], t22History_($dir));
    } finally { removeTestDirectory($dir); }
});

test('Enrollment service: bad bodies, wrong roles, missing profile and ineligible students are refused without writing', function (): void {
    $dir = tempDirectory();
    try {
        [$service, , $users, , , $directory] = requestFixture($dir);
        [$ana, , , $registrar, , $faculty] = t22Actors($users);
        $before = storeBytes($dir);
        foreach ([[['section_id' => 1, 'student_id' => 2], ['student_id']], [[], ['section_id']], [['section_id' => '1'], ['section_id']],
                  [['section_id' => 0], ['section_id']], [['section_id' => 99], ['section_id']]] as [$input, $keys]) {
            $e = httpFailure(fn () => $service->request($ana, $input));
            same([422, $keys], [$e->status, array_keys($e->errors)]);
        }
        same(403, httpFailure(fn () => $service->request($registrar, ['section_id' => 1]))->status);
        same(403, httpFailure(fn () => $service->request($faculty, ['section_id' => 1]))->status);
        $directory->eligible[1] = false;
        $e = httpFailure(fn () => $service->request($ana, ['section_id' => 1]));
        same([409, ['student_id']], [$e->status, array_keys($e->errors)]);
        $directory->eligible[1] = true;
        unset($directory->byUser[4]);
        same(403, httpFailure(fn () => $service->request($ana, ['section_id' => 1]))->status);
        same(403, httpFailure(fn () => $service->mine($ana, []))->status);
        same(403, httpFailure(fn () => $service->drop($ana, 1))->status);
        same(403, httpFailure(fn () => $service->schedule($ana, []))->status);
        same($before, storeBytes($dir));
    } finally { removeTestDirectory($dir); }
});

test('Enrollment service: new requests are refused when the term is not open', function (): void {
    $dir = tempDirectory();
    try {
        [$service, , $users, , $terms] = requestFixture($dir);
        [$ana, , , $registrar] = t22Actors($users);
        $terms->update($registrar, 1, ['enrollment_open' => false]);
        $e = httpFailure(fn () => $service->request($ana, ['section_id' => 1]));
        same([409, ['section_id']], [$e->status, array_keys($e->errors)]);
        check(str_contains($e->errors['section_id'], 'closed'));
    } finally { removeTestDirectory($dir); }
});

test('Enrollment service: duplicates are refused, but a rejected or dropped request can be filed again', function (): void {
    $dir = tempDirectory();
    try {
        [$service, , $users] = requestFixture($dir);
        [$ana, , , $registrar] = t22Actors($users);
        same(1, $service->request($ana, ['section_id' => 1])['id']);
        $e = httpFailure(fn () => $service->request($ana, ['section_id' => 1]));
        same([409, ['section_id']], [$e->status, array_keys($e->errors)]);
        $service->decide($registrar, 1, ['status' => 'rejected', 'reason' => 'Incomplete documents']);
        same(2, $service->request($ana, ['section_id' => 1])['id']);
        $service->drop($ana, 2);
        same(3, $service->request($ana, ['section_id' => 1])['id']);
        $service->decide($registrar, 3, ['status' => 'approved']);
        same(409, httpFailure(fn () => $service->request($ana, ['section_id' => 1]))->status);
    } finally { removeTestDirectory($dir); }
});

test('Enrollment service: a full section refuses requests (pending does not hold a seat)', function (): void {
    $dir = tempDirectory();
    try {
        [$service, $repository, $users] = requestFixture($dir);
        [$ana, $ben, $cara, $registrar] = t22Actors($users);
        $service->request($ben, ['section_id' => 2]);
        $service->request($cara, ['section_id' => 2]); // pending rows do not count against capacity
        $service->decide($registrar, 1, ['status' => 'approved']);
        same(1, $repository->findSection(2)->approvedCount());
        $e = httpFailure(fn () => $service->request($ana, ['section_id' => 2]));
        same([409, ['section_id']], [$e->status, array_keys($e->errors)]);
        check(str_contains($e->errors['section_id'], 'full'));
    } finally { removeTestDirectory($dir); }
});

test('Enrollment service: approval re-checks capacity inside the lock and keeps approved_count consistent', function (): void {
    $dir = tempDirectory();
    try {
        [$service, $repository, $users] = requestFixture($dir);
        [$ana, $ben, , $registrar] = t22Actors($users);
        $service->request($ana, ['section_id' => 2]);
        $service->request($ben, ['section_id' => 2]);
        same('approved', $service->decide($registrar, 1, ['status' => 'approved'])['status']);
        $e = httpFailure(fn () => $service->decide($registrar, 2, ['status' => 'approved']));
        same([409, ['status']], [$e->status, array_keys($e->errors)]);
        same([1, 1], [$repository->findSection(2)->approvedCount(), count($repository->approvedStudentIds(2))]);
        same('pending', $repository->findEnrollment(2)->status());
    } finally { removeTestDirectory($dir); }
});

test('Enrollment service: schedule clashes name the section; tba and no-meeting sections never clash', function (): void {
    $dir = tempDirectory();
    try {
        [$service, , $users] = requestFixture($dir);
        [$ana, $ben, , $registrar] = t22Actors($users);
        $service->request($ana, ['section_id' => 1]);
        $e = httpFailure(fn () => $service->request($ana, ['section_id' => 2]));
        same([409, ['section_id']], [$e->status, array_keys($e->errors)]);
        check(str_contains($e->errors['section_id'], 'CS101-A (section 1)'), $e->errors['section_id']);
        $service->decide($registrar, 1, ['status' => 'approved']);
        same(409, httpFailure(fn () => $service->request($ana, ['section_id' => 2]))->status); // still clashes once approved
        same('pending', $service->request($ana, ['section_id' => 3])['status']);
        same('pending', $service->request($ana, ['section_id' => 4])['status']); // tba, no meetings
        same('pending', $service->request($ben, ['section_id' => 2])['status']); // another student is unaffected
    } finally { removeTestDirectory($dir); }
});

test('Enrollment service: pending rows count at request time but not at approval time (E3)', function (): void {
    $dir = tempDirectory();
    try {
        [$service, $repository, $users, $sections] = requestFixture($dir);
        [$ana, , , $registrar] = t22Actors($users);
        $sections->update($registrar, 2, ['meetings' => [meeting('WED', '10:00', '11:00')]]);
        $service->request($ana, ['section_id' => 1]);
        $service->request($ana, ['section_id' => 2]);
        // Pending rows never block a PATCH (OQ-3 looks at approved rows only), so the two can now overlap.
        $sections->update($registrar, 2, ['meetings' => [meeting('MON', '10:00', '11:00')]]);
        same('approved', $service->decide($registrar, 2, ['status' => 'approved'])['status']); // pending section 1 does not count
        $e = httpFailure(fn () => $service->decide($registrar, 1, ['status' => 'approved']));
        same([409, ['status']], [$e->status, array_keys($e->errors)]);
        check(str_contains($e->errors['status'], 'CS102-A (section 2)'), $e->errors['status']);
        same([0, 1], [$repository->findSection(1)->approvedCount(), $repository->findSection(2)->approvedCount()]);
    } finally { removeTestDirectory($dir); }
});

test('Enrollment service: decisions follow the state machine, record the actor and append history', function (): void {
    $dir = tempDirectory();
    try {
        [$service, $repository, $users] = requestFixture($dir);
        [$ana, $ben, , $registrar, $admin] = t22Actors($users);
        $service->request($ana, ['section_id' => 1]);
        $approved = $service->decide($registrar, 1, ['status' => 'approved']);
        same(['approved', 2, t22Now()], [$approved['status'], $approved['decided_by'], $approved['decided_at']]);
        same(1, $repository->findSection(1)->approvedCount());
        foreach ([['status' => 'approved'], ['status' => 'rejected', 'reason' => 'Late']] as $body) {
            $e = httpFailure(fn () => $service->decide($registrar, 1, $body));
            same([409, ['status']], [$e->status, array_keys($e->errors)]);
        }
        $service->request($ben, ['section_id' => 3]);
        $rejected = $service->decide($admin, 2, ['status' => 'rejected', 'reason' => 'Prerequisite not met']);
        same(['rejected', 1, 'Prerequisite not met'], [$rejected['status'], $rejected['decided_by'], $rejected['reason']]);
        same(0, $repository->findSection(3)->approvedCount()); // rejection never touches the count
        same(409, httpFailure(fn () => $service->decide($registrar, 2, ['status' => 'approved']))->status);
        same(['requested', 'approved', 'requested', 'rejected'], array_column(t22History_($dir), 'action'));
        same([1, 2, 1, 2], array_column(t22History_($dir), 'seq'));
        same(404, httpFailure(fn () => $service->decide($registrar, 99, ['status' => 'approved']))->status);
    } finally { removeTestDirectory($dir); }
});

test('Enrollment M1: a staff account that also owns the student record cannot approve or reject its own enrollment, but can decide others', function (): void {
    $dir = tempDirectory();
    try {
        [$service, , $users, , , $directory] = requestFixture($dir);
        [$ana, $ben, , $registrar] = t22Actors($users);
        $directory->byUser[2] = 1;                                   // the registrar account (user 2) is also student 1
        $service->request($ana, ['section_id' => 1]);                // enrollment 1 = student 1
        $service->request($ben, ['section_id' => 3]);                // enrollment 2 = student 2
        $before = storeBytes($dir);
        foreach ([['status' => 'approved'], ['status' => 'rejected', 'reason' => 'No']] as $body) {
            $e = httpFailure(fn () => $service->decide($registrar, 1, $body));
            same([403, 'Access denied.'], [$e->status, $e->getMessage()]);
        }
        same($before, storeBytes($dir));
        same('approved', $service->decide($registrar, 2, ['status' => 'approved'])['status']);
        same(404, httpFailure(fn () => $service->decide($registrar, 99, ['status' => 'approved']))->status);
    } finally { removeTestDirectory($dir); }
});

test('Enrollment service: a rejection requires a reason of 1 to 255 clean characters', function (): void {
    $dir = tempDirectory();
    try {
        [$service, $repository, $users] = requestFixture($dir);
        [$ana, , , $registrar, , $faculty] = t22Actors($users);
        $service->request($ana, ['section_id' => 1]);
        $before = storeBytes($dir);
        foreach ([[], ['reason' => ''], ['reason' => '   '], ['reason' => "a\x07b"], ['reason' => "x\u{202E}y"], ['reason' => str_repeat('a', 256)],
                  ['reason' => 123], ['reason' => null]] as $extra) {
            $e = httpFailure(fn () => $service->decide($registrar, 1, ['status' => 'rejected'] + $extra));
            same([422, ['reason']], [$e->status, array_keys($e->errors)]);
        }
        // An approval may carry a reason, but a present one is validated too.
        same(['reason'], array_keys(httpFailure(fn () => $service->decide($registrar, 1, ['status' => 'approved', 'reason' => '']))->errors));
        foreach (['pending', 'dropped', 'x', 123, null] as $status) {
            same(['status'], array_keys(httpFailure(fn () => $service->decide($registrar, 1, ['status' => $status]))->errors));
        }
        same(['status'], array_keys(httpFailure(fn () => $service->decide($registrar, 1, []))->errors));
        same(['student_id'], array_keys(httpFailure(fn () => $service->decide($registrar, 1, ['status' => 'approved', 'student_id' => 2]))->errors));
        same(403, httpFailure(fn () => $service->decide($ana, 1, ['status' => 'approved']))->status);
        same(403, httpFailure(fn () => $service->decide($faculty, 1, ['status' => 'approved']))->status);
        same($before, storeBytes($dir));
        $row = $service->decide($registrar, 1, ['status' => 'rejected', 'reason' => str_repeat('é', 255)]);
        same(str_repeat('é', 255), $row['reason']);
    } finally { removeTestDirectory($dir); }
});

test('Enrollment service: the reason is trimmed and shown to the student, who never sees decided_by', function (): void {
    $dir = tempDirectory();
    try {
        [$service, , $users] = requestFixture($dir);
        [$ana, , , $registrar] = t22Actors($users);
        $service->request($ana, ['section_id' => 1]);
        $row = $service->decide($registrar, 1, ['status' => 'rejected', 'reason' => '  Missing documents  ']);
        same('Missing documents', $row['reason']);
        $mine = $service->mine($ana, [])['enrollments'][0];
        same(['rejected', 'Missing documents', false], [$mine['status'], $mine['reason'], array_key_exists('decided_by', $mine)]);
        same(true, array_key_exists('decided_by', $service->list($registrar, [])['enrollments'][0]));
    } finally { removeTestDirectory($dir); }
});

test('Enrollment service: approval re-checks eligibility for the stored student; rejection does not', function (): void {
    $dir = tempDirectory();
    try {
        [$service, $repository, $users, , , $directory] = requestFixture($dir);
        [$ana, $ben, , $registrar] = t22Actors($users);
        $service->request($ana, ['section_id' => 1]);
        $service->request($ben, ['section_id' => 3]);
        $directory->eligible[1] = false;
        $e = httpFailure(fn () => $service->decide($registrar, 1, ['status' => 'approved']));
        same([409, ['status']], [$e->status, array_keys($e->errors)]);
        same(['pending', 0], [$repository->findEnrollment(1)->status(), $repository->findSection(1)->approvedCount()]);
        same('rejected', $service->decide($registrar, 1, ['status' => 'rejected', 'reason' => 'Not active'])['status']);
        same('approved', $service->decide($registrar, 2, ['status' => 'approved'])['status']);
    } finally { removeTestDirectory($dir); }
});

test('Enrollment service: after the term closes the registrar may still decide pending requests, but new requests and student drops are refused (OQ-2, N-1)', function (): void {
    $dir = tempDirectory();
    try {
        [$service, $repository, $users, , $terms] = requestFixture($dir);
        [$ana, $ben, $cara, $registrar] = t22Actors($users);
        $service->request($ana, ['section_id' => 1]);
        $service->request($ben, ['section_id' => 3]);
        $service->decide($registrar, 2, ['status' => 'approved']);
        $terms->update($registrar, 1, ['enrollment_open' => false]);
        same('approved', $service->decide($registrar, 1, ['status' => 'approved'])['status']);
        same(409, httpFailure(fn () => $service->request($cara, ['section_id' => 3]))->status);
        foreach ([[$ana, 1], [$ben, 2]] as [$student, $id]) {
            $e = httpFailure(fn () => $service->drop($student, $id));
            same([409, ['status']], [$e->status, array_keys($e->errors)]);
            same('approved', $repository->findEnrollment($id)->status());
        }
        same([1, 1], [$repository->findSection(1)->approvedCount(), $repository->findSection(3)->approvedCount()]);
        $terms->update($registrar, 1, ['enrollment_open' => true]);
        same('dropped', $service->drop($ana, 1)['status']);
        same(0, $repository->findSection(1)->approvedCount());
    } finally { removeTestDirectory($dir); }
});

test('Enrollment service: only the owner drops a pending or approved row; approved drops free the seat', function (): void {
    $dir = tempDirectory();
    try {
        [$service, $repository, $users] = requestFixture($dir);
        [$ana, $ben, , $registrar] = t22Actors($users);
        $service->request($ana, ['section_id' => 1]);
        $service->request($ana, ['section_id' => 3]);
        $service->request($ana, ['section_id' => 4]);
        $service->decide($registrar, 1, ['status' => 'approved']);
        $service->decide($registrar, 3, ['status' => 'rejected', 'reason' => 'No']);
        $e = httpFailure(fn () => $service->drop($ben, 1));
        same([404, 'Enrollment not found.'], [$e->status, $e->getMessage()]);
        same(404, httpFailure(fn () => $service->drop($ana, 99))->status);
        same(403, httpFailure(fn () => $service->drop($registrar, 1))->status);
        same(409, httpFailure(fn () => $service->drop($ana, 3))->status); // rejected is final
        same('dropped', $service->drop($ana, 2)['status']); // pending: count untouched
        same(1, $repository->findSection(1)->approvedCount());
        $dropped = $service->drop($ana, 1);
        same(['dropped', t22Now(), 2], [$dropped['status'], $dropped['dropped_at'], $repository->findEnrollment(1)->row()['decided_by']]);
        same(0, $repository->findSection(1)->approvedCount());
        same(409, httpFailure(fn () => $service->drop($ana, 1))->status); // no replay
        same(['requested', 'requested', 'requested', 'approved', 'rejected', 'dropped', 'dropped'], array_column(t22History_($dir), 'action'));
    } finally { removeTestDirectory($dir); }
});

test('Enrollment service: /me lists only the caller, filters and paginates; staff list filters and shows decided_by', function (): void {
    $dir = tempDirectory();
    try {
        [$service, , $users] = requestFixture($dir);
        [$ana, $ben, , $registrar, $admin, $faculty] = t22Actors($users);
        $service->request($ana, ['section_id' => 1]);
        $service->request($ana, ['section_id' => 3]);
        $service->request($ben, ['section_id' => 3]);
        $service->decide($registrar, 1, ['status' => 'approved']);
        $mine = $service->mine($ana, []);
        same([1, 2], array_column($mine['enrollments'], 'id'));
        same(['page' => 1, 'per_page' => 20, 'total' => 2], $mine['pagination']);
        same([false, false], array_map(static fn (array $r): bool => array_key_exists('decided_by', $r), $mine['enrollments']));
        same([1], array_column($service->mine($ana, ['status' => 'approved'])['enrollments'], 'id'));
        same([], $service->mine($ana, ['term_id' => '2'])['enrollments']);
        same([2], array_column($service->mine($ana, ['per_page' => '1', 'page' => '2'])['enrollments'], 'id'));
        foreach ([['status' => 'x'], ['term_id' => 'abc'], ['per_page' => '101'], ['page' => '0'], ['status' => ['a']]] as $query) {
            same(422, httpFailure(fn () => $service->mine($ana, $query))->status);
        }
        same(403, httpFailure(fn () => $service->mine($registrar, []))->status);
        same([1, 2, 3], array_column($service->list($registrar, [])['enrollments'], 'id'));
        same([2, 3], array_column($service->list($admin, ['section_id' => '3'])['enrollments'], 'id'));
        same([3], array_column($service->list($registrar, ['student_id' => '2'])['enrollments'], 'id'));
        same([1], array_column($service->list($registrar, ['status' => 'approved', 'term_id' => '1'])['enrollments'], 'id'));
        same(2, $service->list($registrar, [])['enrollments'][0]['decided_by']);
        same(['page' => 2, 'per_page' => 1, 'total' => 3], $service->list($registrar, ['per_page' => '1', 'page' => '2'])['pagination']);
        same(422, httpFailure(fn () => $service->list($registrar, ['student_id' => '0']))->status);
        same(403, httpFailure(fn () => $service->list($ana, []))->status);
        same(403, httpFailure(fn () => $service->list($faculty, []))->status);
    } finally { removeTestDirectory($dir); }
});

test('Enrollment service: the schedule lists approved rows only, with meetings, empty meetings for tba, and instructors', function (): void {
    $dir = tempDirectory();
    try {
        [$service, , $users, , $terms] = requestFixture($dir);
        [$ana, , , $registrar] = t22Actors($users);
        foreach ([4, 3, 1] as $sectionId) { // enrollments 1 (tba), 2 (CS103, stays pending), 3 (CS101)
            $service->request($ana, ['section_id' => $sectionId]);
        }
        $service->decide($registrar, 1, ['status' => 'approved']);
        $service->decide($registrar, 3, ['status' => 'approved']);
        seedAssignment($dir, 1, 10);
        $schedule = $service->schedule($ana, []);
        same(1, $schedule['term_id']);
        same(['CS101', 'CS104'], array_column($schedule['items'], 'course_code')); // section 3 is still pending
        same(['enrollment_id', 'section_id', 'course_code', 'course_title', 'section_code', 'units', 'delivery_mode', 'meetings', 'instructors'],
            array_keys($schedule['items'][0]));
        same([['day' => 'MON', 'start' => '09:00', 'end' => '10:30', 'room' => 'R-101']], $schedule['items'][0]['meetings']);
        same([['faculty_id' => 10, 'name' => null]], $schedule['items'][0]['instructors']);
        same(['tba', [], []], [$schedule['items'][1]['delivery_mode'], $schedule['items'][1]['meetings'], $schedule['items'][1]['instructors']]);
        same($schedule, $service->schedule($ana, ['term_id' => '1']));
        same(404, httpFailure(fn () => $service->schedule($ana, ['term_id' => '99']))->status);
        same(422, httpFailure(fn () => $service->schedule($ana, ['term_id' => 'x']))->status);
        $terms->update($registrar, 1, ['is_current' => false]);
        same(['term_id' => null, 'items' => []], $service->schedule($ana, []));
        same(2, count($service->schedule($ana, ['term_id' => '1'])['items']));
    } finally { removeTestDirectory($dir); }
});

test('Enrollment OQ-3: a meeting PATCH that would clash a student\'s approved classes is refused and writes nothing', function (): void {
    $dir = tempDirectory();
    try {
        [$service, $repository, $users, $sections] = requestFixture($dir);
        [$ana, , , $registrar] = t22Actors($users);
        $service->request($ana, ['section_id' => 1]);
        $service->request($ana, ['section_id' => 3]);
        $service->decide($registrar, 1, ['status' => 'approved']);
        $service->decide($registrar, 2, ['status' => 'approved']);
        $before = storeBytes($dir);
        $e = httpFailure(fn () => $sections->update($registrar, 3, ['meetings' => [meeting('MON', '10:00', '11:00')]]));
        same([409, ['meetings']], [$e->status, array_keys($e->errors)]);
        check(str_contains($e->errors['meetings'], 'Student 1') && str_contains($e->errors['meetings'], 'CS101-A (section 1)'), $e->errors['meetings']);
        same($before, storeBytes($dir));
        // The clash is detected from either side.
        same(409, httpFailure(fn () => $sections->update($registrar, 1, ['meetings' => [meeting('TUE', '09:30', '10:30')]]))->status);
        same($before, storeBytes($dir));
        // No clash: accepted. Unrelated fields never trigger the check.
        same('WED', $sections->update($registrar, 3, ['meetings' => [meeting('WED', '10:00', '11:00')]])['meetings'][0]['day']);
        same('Renamed', $sections->update($registrar, 1, ['course_title' => 'Renamed'])['course_title']);
        same(1, $repository->findSection(1)->approvedCount());
    } finally { removeTestDirectory($dir); }
});

test('Enrollment OQ-3: pending and dropped rows never block a meeting PATCH, and approval then re-checks', function (): void {
    $dir = tempDirectory();
    try {
        [$service, , $users, $sections] = requestFixture($dir);
        [$ana, $ben, , $registrar] = t22Actors($users);
        foreach ([[$ana, 1], [$ana, 3], [$ben, 1], [$ben, 3]] as [$student, $sectionId]) { // enrollments 1..4
            $service->request($student, ['section_id' => $sectionId]);
        }
        foreach ([1, 3, 4] as $id) {
            $service->decide($registrar, $id, ['status' => 'approved']); // ana: 1 approved, 3 pending; ben: 1 and 3 approved
        }
        $service->drop($ben, 4); // ben leaves section 3
        // Section 3 now has no approved student, so moving it onto section 1's time is allowed.
        $sections->update($registrar, 3, ['meetings' => [meeting('MON', '10:00', '11:00')]]);
        $e = httpFailure(fn () => $service->decide($registrar, 2, ['status' => 'approved'])); // the approval-time check still holds
        same([409, ['status']], [$e->status, array_keys($e->errors)]);
    } finally { removeTestDirectory($dir); }
});

test('Enrollment summary source and section directory report approved students ascending and counts per status', function (): void {
    $dir = tempDirectory();
    try {
        [$service, $repository, $users] = requestFixture($dir);
        [$ana, $ben, $cara, $registrar] = t22Actors($users);
        $summary = new EnrollmentSummarySource($repository);
        same(['pending' => 0, 'approved' => 0, 'rejected' => 0, 'dropped' => 0], $summary->countsFor(1));
        $service->request($ben, ['section_id' => 1]);
        $service->request($ana, ['section_id' => 1]);
        $service->request($cara, ['section_id' => 1]);
        $service->request($ana, ['section_id' => 3]);
        $service->request($ana, ['section_id' => 4]);
        $service->decide($registrar, 1, ['status' => 'approved']);
        $service->decide($registrar, 2, ['status' => 'approved']);
        $service->decide($registrar, 4, ['status' => 'rejected', 'reason' => 'No']);
        $directory = new RepositorySectionDirectory($repository, new UnavailableFacultyIdentity());
        same([1, 2], $directory->approvedStudentIds(1)); // ascending ids; pending (cara) excluded
        $service->drop($ben, 1);
        same([1], $directory->approvedStudentIds(1)); // dropped excluded
        same([], $directory->approvedStudentIds(99));
        same(['pending' => 1, 'approved' => 1, 'rejected' => 1, 'dropped' => 0], $summary->countsFor(1));
        same(['pending' => 0, 'approved' => 0, 'rejected' => 0, 'dropped' => 1], $summary->countsFor(2));
    } finally { removeTestDirectory($dir); }
});
