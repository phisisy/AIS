<?php
declare(strict_types=1);

require_once __DIR__ . '/EnrollmentSupport.php';

const TERM_KEYS = ['id', 'code', 'name', 'start_date', 'end_date', 'is_current', 'enrollment_open', 'created_at', 'updated_at'];

test('Terms: create normalizes the code, defaults booleans to false and returns the exact shape', function (): void {
    $directory = tempDirectory();
    try {
        [$terms, , , $users] = enrollmentFixture($directory);
        $term = $terms->create($users->findById(2), termInput(['code' => ' 2026-1s ', 'name' => '  First  ']));
        same(TERM_KEYS, array_keys($term));
        same(['2026-1S', 'First', false, false, 1], [$term['code'], $term['name'], $term['is_current'], $term['enrollment_open'], $term['id']]);
        same('2026-05-28T20:26:40Z', $term['created_at']);
        same($term['created_at'], $term['updated_at']);
    } finally {
        removeTestDirectory($directory);
    }
});

test('Terms: required fields, formats and strict booleans are validated with the right keys', function (): void {
    $directory = tempDirectory();
    try {
        [$terms, , $repository, $users] = enrollmentFixture($directory);
        $registrar = $users->findById(2);
        foreach (['code', 'name', 'start_date', 'end_date'] as $field) {
            $input = termInput();
            unset($input[$field]);
            $e = httpFailure(fn () => $terms->create($registrar, $input));
            same([422, [$field => 'This field is required.']], [$e->status, $e->errors]);
        }
        $bad = [
            'code' => ['A', '', 'bad code', '-AB', str_repeat('A', 21), 'AB_C', 123, null, ['A1']],
            'name' => ['', '   ', str_repeat('x', 101), "a\x00b", "a\u{202E}b", "a\nb", 7, "\xC3\x28"],
            'start_date' => ['2026-02-30', '2026-2-01', '20260801', 20260801, null, '2026-13-01', '2026-08-10 ', "2026-08-10\n"],
            'end_date' => ['2026-02-30', '2026-2-01', 20261218, ['2026-12-18']],
            'is_current' => [1, 'true', 0, 'false', null, 'yes'],
            'enrollment_open' => [1, 'true', [], null],
        ];
        foreach ($bad as $field => $values) {
            foreach ($values as $value) {
                $e = httpFailure(fn () => $terms->create($registrar, termInput([$field => $value])));
                same(422, $e->status);
                check(array_keys($e->errors) === [$field], "{$field}=" . json_encode($value) . ' gave ' . json_encode($e->errors));
                check(!str_contains(json_encode($e->errors), 'bad code'), 'Error echoed input.');
            }
        }
        foreach ([['2026-12-18', '2026-12-18'], ['2026-12-19', '2026-12-18']] as [$start, $end]) {
            $e = httpFailure(fn () => $terms->create($registrar, termInput(['start_date' => $start, 'end_date' => $end])));
            same([422, ['end_date' => 'The end date must be after the start date.']], [$e->status, $e->errors]);
        }
        foreach ([['id' => 9], ['role' => 'admin'], ['created_at' => 'x']] as $extra) {
            $e = httpFailure(fn () => $terms->create($registrar, termInput($extra)));
            same([422, array_fill_keys(array_keys($extra), 'Unknown field.')], [$e->status, $e->errors]);
        }
        same([], $repository->terms());
    } finally {
        removeTestDirectory($directory);
    }
});

test('Terms: code uniqueness (409) holds after normalization; codes accept hyphen and digits', function (): void {
    $directory = tempDirectory();
    try {
        [$terms, , $repository, $users] = enrollmentFixture($directory);
        $registrar = $users->findById(2);
        $terms->create($registrar, termInput());
        foreach (['2026-1S', '2026-1s', '  2026-1s  '] as $variant) {
            $e = httpFailure(fn () => $terms->create($registrar, termInput(['code' => $variant, 'name' => 'Other'])));
            same([409, ['code' => 'This term code is already in use.']], [$e->status, $e->errors]);
        }
        $terms->create($registrar, termInput(['code' => '2026-2S', 'start_date' => '2027-01-05', 'end_date' => '2027-05-20']));
        $terms->create($registrar, termInput(['code' => 'AB']));
        same(3, count($repository->terms()));
    } finally {
        removeTestDirectory($directory);
    }
});

test('Terms: is_current on create and PATCH keeps exactly one current term; false leaves none', function (): void {
    $directory = tempDirectory();
    try {
        [$terms, , $repository, $users] = enrollmentFixture($directory);
        $registrar = $users->findById(2);
        $current = static fn (): array => array_values(array_map(static fn ($t) => $t->id(),
            array_filter($repository->terms(), static fn ($t) => $t->isCurrent())));
        $terms->create($registrar, termInput(['is_current' => true]));
        same([1], $current());
        $terms->create($registrar, termInput(['code' => 'T2', 'is_current' => true]));
        same([2], $current());
        $terms->create($registrar, termInput(['code' => 'T3']));
        same([2], $current());
        $terms->update($registrar, 3, ['is_current' => true]);
        same([3], $current());
        $terms->update($registrar, 1, ['is_current' => true, 'enrollment_open' => true]);
        same([1], $current());
        $terms->update($registrar, 1, ['is_current' => false]);
        same([], $current());
        // Re-setting true on the already-current term is stable.
        $terms->update($registrar, 2, ['is_current' => true]);
        $terms->update($registrar, 2, ['is_current' => true]);
        same([2], $current());
        // enrollment_open is independent of is_current.
        same([false, true], [$repository->findTerm(3)->toArray()['enrollment_open'], $repository->findTerm(1)->toArray()['enrollment_open']]);
    } finally {
        removeTestDirectory($directory);
    }
});

test('Terms: PATCH rules (empty body, unknown id, immutable code, merged-row date check, overlap allowed)', function (): void {
    $directory = tempDirectory();
    try {
        [$terms, , $repository, $users] = enrollmentFixture($directory);
        $registrar = $users->findById(2);
        $terms->create($registrar, termInput());
        $e = httpFailure(fn () => $terms->update($registrar, 1, []));
        same([422, ['body' => 'Provide at least one field to change.']], [$e->status, $e->errors]);
        $e = httpFailure(fn () => $terms->update($registrar, 99, ['name' => 'X']));
        same([404, 'Term not found.'], [$e->status, $e->getMessage()]);
        foreach (['code', 'id', 'created_at', 'password'] as $field) {
            $e = httpFailure(fn () => $terms->update($registrar, 1, [$field => 'Z9']));
            same([422, [$field => 'Unknown field.']], [$e->status, $e->errors]);
        }
        // end_date alone before the stored start_date, and start_date alone after the stored end_date.
        $e = httpFailure(fn () => $terms->update($registrar, 1, ['end_date' => '2026-08-01']));
        same([422, ['end_date' => 'The end date must be after the start date.']], [$e->status, $e->errors]);
        $e = httpFailure(fn () => $terms->update($registrar, 1, ['start_date' => '2026-12-18']));
        same(422, $e->status);
        $e = httpFailure(fn () => $terms->update($registrar, 1, ['name' => '']));
        same(['name'], array_keys($e->errors));
        // Invalid input on a missing row is a 404 before validation (find precedes field checks).
        same(404, httpFailure(fn () => $terms->update($registrar, 99, ['name' => '']))->status);
        // Failed PATCHes wrote nothing.
        same(['2026-08-10', '2026-12-18'], [$repository->findTerm(1)->startDate(), $repository->findTerm(1)->endDate()]);
        $ok = $terms->update($registrar, 1, ['end_date' => '2026-12-31', 'name' => 'Renamed']);
        same(['Renamed', '2026-12-31', '2026-08-10'], [$ok['name'], $ok['end_date'], $ok['start_date']]);
        // Terms may overlap.
        $terms->create($registrar, termInput(['code' => 'SUM', 'start_date' => '2026-10-01', 'end_date' => '2026-11-01']));
        same(2, count($repository->terms()));
    } finally {
        removeTestDirectory($directory);
    }
});

test('Terms: student, faculty and unrelated actors cannot create or update through the service (403)', function (): void {
    $directory = tempDirectory();
    try {
        [$terms, , $repository, $users] = enrollmentFixture($directory);
        $terms->create($users->findById(2), termInput());
        foreach ([3, 4] as $id) {
            $actor = $users->findById($id);
            same(403, httpFailure(fn () => $terms->create($actor, termInput(['code' => 'ZZ'])))->status);
            same(403, httpFailure(fn () => $terms->update($actor, 1, ['name' => 'Hacked']))->status);
        }
        same(1, count($repository->terms()));
        same('First Semester 2026-2027', $repository->findTerm(1)->toArray()['name']);
        // Admin is staff too.
        $terms->create($users->findById(1), termInput(['code' => 'ADM']));
        same(2, count($repository->terms()));
    } finally {
        removeTestDirectory($directory);
    }
});

test('Terms: the list is ordered by start_date then id and empty by default', function (): void {
    $directory = tempDirectory();
    try {
        [$terms, , , $users] = enrollmentFixture($directory);
        same(['terms' => []], $terms->list());
        $r = $users->findById(2);
        $terms->create($r, termInput(['code' => 'TC', 'start_date' => '2027-01-01', 'end_date' => '2027-05-01']));
        $terms->create($r, termInput(['code' => 'TA', 'start_date' => '2026-01-01', 'end_date' => '2026-05-01']));
        $terms->create($r, termInput(['code' => 'TB', 'start_date' => '2026-01-01', 'end_date' => '2026-06-01']));
        same(['TA', 'TB', 'TC'], array_column($terms->list()['terms'], 'code'));
        same([2, 3, 1], array_column($terms->list()['terms'], 'id'));
    } finally {
        removeTestDirectory($directory);
    }
});
