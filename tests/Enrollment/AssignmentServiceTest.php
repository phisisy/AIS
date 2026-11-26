<?php
declare(strict_types=1);

require_once __DIR__ . '/EnrollmentSupport.php';

function assignmentFixture(string $directory, ?StubFacultyIdentity $stub = null): array
{
    $stub ??= new StubFacultyIdentity(array_fill_keys(range(10, 99), ['name' => 'Instructor', 'active' => true]));
    [$terms, $sections, $repository, $users] = enrollmentFixture($directory, $stub);
    $actor = $users->findById(2);
    $terms->create($actor, termInput());
    $sections->create($actor, sectionInput(['meetings' => [meeting('MON', '09:00', '10:00')]]));
    return [$sections, $repository, $users, $stub];
}

test('Assignments: success, exact section shape, audit and order preserving unassign', function (): void {
    $dir = tempDirectory();
    try {
        [$sections, $repository, $users] = assignmentFixture($dir);
        $actor = $users->findById(2);
        $row = $sections->assignInstructor($actor, 1, assignInput(10));
        same(['id', 'term_id', 'course_code', 'course_title', 'units', 'section_code', 'capacity', 'approved_count',
            'remaining', 'delivery_mode', 'meetings', 'instructors', 'created_at', 'updated_at'], array_keys($row));
        same([['faculty_id' => 10, 'name' => 'Instructor']], $row['instructors']);
        $sections->assignInstructor($actor, 1, assignInput(11));
        $sections->assignInstructor($actor, 1, assignInput(12));
        same([10, 11, 12], $repository->assignedFacultyIds([1])[1]);
        $row = $sections->unassignInstructor($actor, 1, 11);
        same([10, 12], array_column($row['instructors'], 'faculty_id'));
        $store = json_decode(storeBytes($dir), true, 512, JSON_THROW_ON_ERROR);
        same([1, 2, 3, 4], array_column($store['assignment_history'], 'seq'));
        same(['assigned', 'assigned', 'assigned', 'unassigned'], array_column($store['assignment_history'], 'action'));
        same(2, $store['assignment_history'][0]['actor_id']);
        same(gmdate('Y-m-d\TH:i:s\Z', 1_780_000_000), $store['assignment_history'][0]['at']);
    } finally { removeTestDirectory($dir); }
});

test('Assignments: unassign resolves remaining names without a direct identity check', function (): void {
    $dir = tempDirectory();
    try {
        [$sections, , $users, $stub] = assignmentFixture($dir);
        $actor = $users->findById(2);
        foreach ([10, 11, 12] as $id) { $sections->assignInstructor($actor, 1, assignInput($id)); }
        $findCalls = $stub->findCalls;
        $findManyCalls = $stub->findManyCalls;
        $row = $sections->unassignInstructor($actor, 1, 11);
        same([['faculty_id' => 10, 'name' => 'Instructor'], ['faculty_id' => 12, 'name' => 'Instructor']], $row['instructors']);
        same($findManyCalls + 1, $stub->findManyCalls);
        // The stub's findMany delegates to find once per remaining ID; unassign adds no direct find call.
        same($findCalls + 2, $stub->findCalls);
    } finally { removeTestDirectory($dir); }
});

test('Assignments: failed name lookup does not undo unassign or duplicate its audit', function (): void {
    $dir = tempDirectory();
    try {
        [$sections, , $users, $stub] = assignmentFixture($dir);
        $actor = $users->findById(2);
        $sections->assignInstructor($actor, 1, assignInput(10));
        $sections->assignInstructor($actor, 1, assignInput(11));
        $findCalls = $stub->findCalls;
        $findManyCalls = $stub->findManyCalls;
        $stub->throw = true;
        $row = $sections->unassignInstructor($actor, 1, 10);
        same([['faculty_id' => 11, 'name' => null]], $row['instructors']);
        same($findCalls, $stub->findCalls);
        same($findManyCalls + 1, $stub->findManyCalls);
        $store = json_decode(storeBytes($dir), true, 512, JSON_THROW_ON_ERROR);
        same([['section_id' => 1, 'faculty_id' => 11]], $store['assignments']);
        $unassigned = array_values(array_filter($store['assignment_history'], static fn (array $entry): bool => $entry['action'] === 'unassigned'));
        same(1, count($unassigned));
        same([1, 10], [$unassigned[0]['section_id'], $unassigned[0]['faculty_id']]);
    } finally { removeTestDirectory($dir); }
});

test('Assignments: inactive and unknown faculty can still be unassigned', function (): void {
    $dir = tempDirectory();
    try {
        [$sections, $repository, $users, $stub] = assignmentFixture($dir);
        $actor = $users->findById(2);
        $sections->assignInstructor($actor, 1, assignInput(10));
        $sections->assignInstructor($actor, 1, assignInput(11));
        $stub->rows[10]['active'] = false;
        same([11], array_column($sections->unassignInstructor($actor, 1, 10)['instructors'], 'faculty_id'));
        unset($stub->rows[11]);
        same([], $sections->unassignInstructor($actor, 1, 11)['instructors']);
        same([], $repository->assignedFacultyIds([1])[1]);
    } finally { removeTestDirectory($dir); }
});

test('Assignments: format, unknown identity, failures, missing rows and role checks do not write', function (): void {
    $dir = tempDirectory();
    try {
        [$sections, , $users, $stub] = assignmentFixture($dir);
        $actor = $users->findById(2);
        $before = storeBytes($dir);
        same(404, httpFailure(fn () => $sections->assignInstructor($actor, 999, assignInput()))->status);
        same(0, $stub->findCalls);
        foreach ([[], ['faculty_id' => '12'], ['faculty_id' => 0], ['faculty_id' => -1], ['faculty_id' => 1.5], ['faculty_id' => null]] as $input) {
            $e = httpFailure(fn () => $sections->assignInstructor($actor, 1, $input));
            same(422, $e->status);
            same(['faculty_id'], array_keys($e->errors));
        }
        foreach ([null, ['name' => 'Inactive', 'active' => false]] as $row) {
            $stub->rows[10] = $row;
            same(['faculty_id' => 'Unknown or inactive faculty member.'], httpFailure(fn () => $sections->assignInstructor($actor, 1, assignInput()))->errors);
        }
        $stub->rows[10] = ['name' => 'Instructor', 'active' => true];
        foreach (['mismatch', 'throw', 'throwError'] as $mode) {
            $stub->$mode = true;
            same(['faculty_id' => 'Unknown or inactive faculty member.'], httpFailure(fn () => $sections->assignInstructor($actor, 1, assignInput()))->errors);
            $stub->$mode = false;
        }
        foreach ([3, 4] as $id) {
            same(403, httpFailure(fn () => $sections->assignInstructor($users->findById($id), 1, assignInput()))->status);
            same(403, httpFailure(fn () => $sections->unassignInstructor($users->findById($id), 1, 10))->status);
        }
        same(404, httpFailure(fn () => $sections->unassignInstructor($actor, 1, 10))->status);
        same(404, httpFailure(fn () => $sections->unassignInstructor($actor, 999, 10))->status);
        same($before, storeBytes($dir));
    } finally { removeTestDirectory($dir); }
});

test('Assignments: duplicate and sixth are 409; names fail open for display and unassign', function (): void {
    $dir = tempDirectory();
    try {
        [$sections, $repository, $users, $stub] = assignmentFixture($dir);
        $actor = $users->findById(2);
        foreach (range(10, 14) as $id) { $sections->assignInstructor($actor, 1, assignInput($id)); }
        $before = storeBytes($dir);
        same(['faculty_id' => 'This instructor is already assigned to this section.'], httpFailure(fn () => $sections->assignInstructor($actor, 1, assignInput(10)))->errors);
        same(['instructors' => 'A section can have at most 5 instructors.'], httpFailure(fn () => $sections->assignInstructor($actor, 1, assignInput(15)))->errors);
        same($before, storeBytes($dir));
        $stub->throw = true;
        same(null, $sections->get(1)['instructors'][0]['name']);
        $stub->findCalls = 0;
        $stub->findManyCalls = 0;
        $sections->unassignInstructor($actor, 1, 10);
        same(0, $stub->findCalls);
        same(1, $stub->findManyCalls);
        same([11, 12, 13, 14], $repository->assignedFacultyIds([1])[1]);
        $stub->throw = false;
        $stub->rows[11] = ['name' => 'Former', 'active' => false];
        same('Former', $sections->get(1)['instructors'][0]['name']);
        $stub->findManyCalls = 0;
        $sections->list([]);
        same(1, $stub->findManyCalls);
    } finally { removeTestDirectory($dir); }
});

test('Assignments: meeting conflict, back-to-back, cross-term, zero meetings and PATCH recheck', function (): void {
    $dir = tempDirectory();
    try {
        [$sections, $repository, $users] = assignmentFixture($dir);
        $actor = $users->findById(2);
        $sections->create($actor, sectionInput(['section_code' => 'B', 'meetings' => [meeting('MON', '09:30', '10:30')]]));
        $sections->create($actor, sectionInput(['section_code' => 'C', 'meetings' => [meeting('MON', '10:00', '11:00')]]));
        $sections->create($actor, sectionInput(['section_code' => 'D', 'delivery_mode' => 'tba', 'meetings' => []]));
        $sections->assignInstructor($actor, 1, assignInput());
        $before = storeBytes($dir);
        $e = httpFailure(fn () => $sections->assignInstructor($actor, 2, assignInput()));
        same([409, ['faculty_id' => 'This instructor already teaches CS101-A (section 1) at an overlapping time.']], [$e->status, $e->errors]);
        same($before, storeBytes($dir));
        $sections->assignInstructor($actor, 3, assignInput());
        $sections->assignInstructor($actor, 4, assignInput());
        $before = storeBytes($dir);
        $e = httpFailure(fn () => $sections->update($actor, 3, ['meetings' => [meeting('MON', '09:45', '10:45')]]));
        same([409, ['meetings' => 'Instructor 10 already teaches CS101-A (section 1) at an overlapping time.']], [$e->status, $e->errors]);
        same($before, storeBytes($dir));
        same('online_async', $sections->update($actor, 3, ['delivery_mode' => 'online_async'])['delivery_mode']);
        $sections->update($actor, 4, ['meetings' => [meeting('TUE', '09:00', '10:00')]]);
        $sections->assignInstructor($actor, 2, assignInput(11));
        $sections->update($actor, 2, ['delivery_mode' => 'tba']);
        same(409, httpFailure(fn () => $sections->assignInstructor($actor, 1, assignInput(11)))->status);
        $terms = new App\Enrollment\Services\TermService($repository, new App\Auth\Services\AuthorizationService());
        $terms->create($actor, termInput(['code' => 'NEXT']));
        $sections->create($actor, sectionInput(['term_id' => 2]));
        $sections->assignInstructor($actor, 5, assignInput());
        same([1, 3, 4, 5], $repository->sectionIdsForFaculty(10));
    } finally { removeTestDirectory($dir); }
});

test('Assignments: other zero-meeting section, different day and mismatched names stay safe', function (): void {
    $dir = tempDirectory();
    try {
        [$sections, $repository, $users, $stub] = assignmentFixture($dir);
        $actor = $users->findById(2);
        $sections->create($actor, sectionInput(['section_code' => 'B', 'delivery_mode' => 'online_async', 'meetings' => []]));
        $sections->create($actor, sectionInput(['section_code' => 'C', 'meetings' => [meeting('TUE', '09:00', '10:00')]]));
        $sections->assignInstructor($actor, 2, assignInput());
        $sections->assignInstructor($actor, 1, assignInput());
        $sections->assignInstructor($actor, 3, assignInput());
        $stub->mismatch = true;
        same(null, $sections->get(1)['instructors'][0]['name']);
        unset($stub->rows[10]);
        same(null, $sections->get(1)['instructors'][0]['name']);
        same([1, 2, 3], $repository->sectionIdsForFaculty(10));
    } finally { removeTestDirectory($dir); }
});
