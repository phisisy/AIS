<?php
declare(strict_types=1);

require_once __DIR__ . '/FacultySupport.php';

test('Faculty service: create returns the exact shape and two history entries with actor and clock', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        $row = $f['service']->create($f['users']->findById(2), facultyInput(['employee_number' => ' emp-001 ']));
        same(['id', 'user_id', 'employee_number', 'first_name', 'last_name', 'department', 'status', 'created_at', 'updated_at'], array_keys($row));
        // The fixture clock is 1_780_000_000 = 2026-05-28T20:26:40Z (the old literal was the 1_700_000_000 time of day).
        same([1, 3, 'EMP-001', 'active', '2026-05-28T20:26:40Z'], [$row['id'], $row['user_id'], $row['employee_number'], $row['status'], $row['created_at']]);
        same($row['created_at'], $row['updated_at']);
        $store = json_decode(facultyStoreBytes($dir), true, 512, JSON_THROW_ON_ERROR);
        same(['status', 'department'], array_column($store['history'], 'field'));
        same([1, 2], array_column($store['history'], 'seq'));
        same([null, null], array_column($store['history'], 'from'));
        same([2, 2], array_column($store['history'], 'actor_id'));
        same(['seq', 'at', 'faculty_id', 'field', 'from', 'to', 'actor_id'], array_keys($store['history'][0]));
    } finally { removeTestDirectory($dir); }
});

test('Faculty service: create validation (required, user_id, employee number, names, department, status)', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        $actor = $f['users']->findById(2);
        $create = static fn (array $input) => static fn () => $f['service']->create($actor, $input);
        foreach (['user_id', 'employee_number', 'first_name', 'last_name', 'department'] as $field) {
            $input = facultyInput();
            unset($input[$field]);
            same([$field => 'This field is required.'], facultyValidation($create($input)));
        }
        foreach (['12', 0, -1, 1.5, null] as $bad) {
            same(['user_id' => 'Provide the id of an existing faculty account.'], facultyValidation($create(facultyInput(['user_id' => $bad]))));
        }
        // missing, inactive, student-only and registrar-only accounts share one message
        foreach ([99, 5, 4, 2] as $userId) {
            same(['user_id' => 'The account must exist, be active and hold the faculty role.'], facultyValidation($create(facultyInput(['user_id' => $userId]))));
        }
        foreach (['AB', str_repeat('A', 21), 'EMP 001', "\u{C9}01", '-EMP', 5] as $bad) {
            same(['employee_number' => 'Use the employee number format.'], facultyValidation($create(facultyInput(['employee_number' => $bad]))));
        }
        foreach (['', '   ', str_repeat('a', 71), "A\x07", "A\u{202E}", "A\u{200B}", "\xC3\x28", 5] as $bad) {
            $errors = facultyValidation($create(facultyInput(['first_name' => $bad, 'last_name' => $bad])));
            same(['first_name', 'last_name'], array_keys($errors));
            same('Use 1 to 70 characters without control characters.', $errors['first_name']);
        }
        foreach (['', str_repeat('d', 101), "D\x07"] as $bad) {
            same(['department' => 'Use 1 to 100 characters without control characters.'], facultyValidation($create(facultyInput(['department' => $bad]))));
        }
        same(['status' => 'Use one of: active, inactive.'], facultyValidation($create(facultyInput(['status' => 'retired']))));
        same('active', $f['service']->create($actor, facultyInput())['status']);
        $long = $f['service']->create($actor, facultyInput(['user_id' => 6, 'employee_number' => 'EMP-LONG', 'first_name' => str_repeat('a', 70),
            'last_name' => str_repeat('b', 70), 'department' => str_repeat('d', 100), 'status' => 'inactive']));
        same('inactive', $long['status']);
        // 70 + 70 characters still make a valid identity (display name 142 characters)
        $identity = $f['identity']->find($long['id']);
        same(142, mb_strlen($identity->displayName));
        same(1, count(json_decode(facultyStoreBytes($dir), true)['faculty']) - 1);
    } finally { removeTestDirectory($dir); }
});

test('Faculty service: duplicates are 409 inside the lock and may be combined', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        $actor = $f['users']->findById(2);
        $f['service']->create($actor, facultyInput());
        $before = facultyStoreBytes($dir);
        $error = httpFailure(fn () => $f['service']->create($actor, facultyInput(['employee_number' => 'EMP-002'])));
        same([409, ['user_id' => 'This account already has a faculty profile.']], [$error->status, $error->errors]);
        $error = httpFailure(fn () => $f['service']->create($actor, facultyInput(['user_id' => 6, 'employee_number' => 'emp-001'])));
        same([409, ['employee_number' => 'This employee number is already in use.']], [$error->status, $error->errors]);
        $error = httpFailure(fn () => $f['service']->create($actor, facultyInput()));
        same(['user_id', 'employee_number'], array_keys($error->errors));
        same($before, facultyStoreBytes($dir));
    } finally { removeTestDirectory($dir); }
});

test('Faculty service: update history rules, 404 and empty body', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        $actor = $f['users']->findById(1);
        facultyProfiles($f);
        $count = static fn (): int => count(json_decode(facultyStoreBytes($dir), true)['history']);
        $base = $count();
        same(['body' => 'Provide at least one field to change.'], facultyValidation(fn () => $f['service']->update($actor, 1, [])));
        same('inactive', $f['service']->update($actor, 1, ['status' => 'inactive'])['status']);
        same($base + 1, $count());
        $f['service']->update($actor, 1, ['department' => 'Mathematics']);
        same($base + 2, $count());
        $f['service']->update($actor, 1, ['first_name' => 'Benito', 'last_name' => 'Cruz-Santos']);
        same($base + 2, $count());
        $row = $f['service']->update($actor, 1, ['status' => 'inactive']);
        same($base + 2, $count());
        same(gmdate('Y-m-d\TH:i:s\Z', 1_780_000_000), $row['updated_at']);
        $last = json_decode(facultyStoreBytes($dir), true)['history'];
        same(['status', 'inactive', 'active', 1], [$last[$base]['field'], $last[$base]['to'], $last[$base]['from'], $last[$base]['actor_id']]);
        same('Faculty profile not found.', httpFailure(fn () => $f['service']->update($actor, 99, ['status' => 'inactive']))->getMessage());
        same(['first_name' => 'Use 1 to 70 characters without control characters.'], facultyValidation(fn () => $f['service']->update($actor, 1, ['first_name' => ''])));
    } finally { removeTestDirectory($dir); }
});

test('Faculty service: list search, status filter, pagination bounds and order', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        $actor = $f['users']->findById(2);
        facultyProfiles($f);
        $f['service']->update($actor, 3, ['status' => 'inactive']);
        $ids = static fn (array $query): array => array_column($f['service']->list($actor, $query)['faculty'], 'id');
        same([1, 2, 3], $ids([]));
        same([1], $ids(['search' => 'emp-006']));
        same([2], $ids(['search' => 'ANA']));
        same([3], $ids(['search' => 'lIm']));
        same([3], $ids(['status' => 'inactive']));
        same([1, 2], $ids(['status' => 'active']));
        same([2], $ids(['page' => '2', 'per_page' => '1']));
        same(3, $f['service']->list($actor, ['per_page' => '1'])['pagination']['total']);
        foreach ([['page' => '0'], ['page' => '1000001'], ['page' => 'x'], ['per_page' => '0'], ['per_page' => '101'], ['status' => 'x'], ['search' => str_repeat('a', 101)]] as $query) {
            same(422, httpFailure(fn () => $f['service']->list($actor, $query))->status);
        }
    } finally { removeTestDirectory($dir); }
});

test('Faculty service: role checks repeat inside the service', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        facultyProfiles($f);
        foreach ([4, 3] as $userId) {
            $actor = $f['users']->findById($userId);
            foreach ([fn () => $f['service']->list($actor, []), fn () => $f['service']->get($actor, 1),
                fn () => $f['service']->create($actor, facultyInput(['user_id' => 8, 'employee_number' => 'EMP-008'])),
                fn () => $f['service']->update($actor, 1, ['status' => 'inactive'])] as $call) {
                same(403, httpFailure($call)->status);
            }
        }
        foreach ([2, 1] as $userId) {
            $actor = $f['users']->findById($userId);
            same(403, httpFailure(fn () => $f['service']->me($actor))->status);
            same(403, httpFailure(fn () => $f['service']->classes($actor, []))->status);
        }
        same('active', $f['service']->get($f['users']->findById(1), 1)['status']);
    } finally { removeTestDirectory($dir); }
});

test('Faculty service: me returns the own profile, 404 without one, inactive profile still readable', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        $registrar = $f['users']->findById(2);
        same('Faculty profile not found.', httpFailure(fn () => $f['service']->me($f['users']->findById(3)))->getMessage());
        facultyProfiles($f);
        same([2, 3], [$f['service']->me($f['users']->findById(3))['id'], $f['service']->me($f['users']->findById(3))['user_id']]);
        $f['service']->update($registrar, 2, ['status' => 'inactive']);
        same('inactive', $f['service']->me($f['users']->findById(3))['status']);
    } finally { removeTestDirectory($dir); }
});

test('Faculty service: classes are the caller own sections only, filtered by term, ascending', function (): void {
    $dir = tempDirectory();
    try {
        $f = facultyFixture($dir);
        $registrar = $f['users']->findById(2);
        same('Faculty profile not found.', httpFailure(fn () => $f['service']->classes($f['users']->findById(3), []))->getMessage());
        facultyProfiles($f);
        $f['terms']->create($registrar, termInput());
        $f['terms']->create($registrar, termInput(['code' => '2026-2S', 'name' => 'Second', 'start_date' => '2027-01-10', 'end_date' => '2027-05-18']));
        $f['sections']->create($registrar, sectionInput(['section_code' => 'A']));
        $f['sections']->create($registrar, sectionInput(['section_code' => 'B', 'meetings' => [meeting('TUE')]]));
        $f['sections']->create($registrar, sectionInput(['term_id' => 2, 'section_code' => 'C']));
        // user 3 is faculty 2; faculty 3 (user 7) teaches elsewhere; user id 3 must never be read as faculty id 3 (M27)
        $f['sections']->assignInstructor($registrar, 3, ['faculty_id' => 2]);
        $f['sections']->assignInstructor($registrar, 1, ['faculty_id' => 2]);
        $f['sections']->assignInstructor($registrar, 2, ['faculty_id' => 3]);
        $faculty = $f['users']->findById(3);
        $classes = $f['service']->classes($faculty, [])['classes'];
        same([1, 3], array_column($classes, 'section_id'));
        same(['section_id', 'term_id', 'course_code', 'section_code'], array_keys($classes[0]));
        same([3], array_column($f['service']->classes($faculty, ['term_id' => '2'])['classes'], 'section_id'));
        same([], $f['service']->classes($faculty, ['term_id' => '9'])['classes']);
        foreach (['abc', '0', '-1', '1.5', ''] as $bad) {
            same(['term_id' => 'Use a positive whole number.'], httpFailure(fn () => $f['service']->classes($faculty, ['term_id' => $bad]))->errors);
        }
        $f['service']->update($registrar, 2, ['status' => 'inactive']);
        same([], $f['service']->classes($faculty, [])['classes']);
    } finally { removeTestDirectory($dir); }
});

test('Employee number policy: configurable pattern, normalization and fail-closed construction', function (): void {
    $policy = new App\Faculty\Services\EmployeeNumberPolicy('FAC-[0-9]{4}');
    same('FAC-0001', $policy->normalize(' fac-0001 '));
    same(null, $policy->normalize('EMP-001'));
    same(null, $policy->normalize(str_repeat('A', 33)));
    same(null, $policy->normalize(1));
    foreach (['', '(', 'a~b', str_repeat('a', 201)] as $bad) {
        throws(RuntimeException::class, static fn () => new App\Faculty\Services\EmployeeNumberPolicy($bad));
    }
});
