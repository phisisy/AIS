<?php
declare(strict_types=1);

require_once __DIR__ . '/EnrollmentSupport.php';

/** Fixture with two terms (ids 1, 2). */
function sectionFixture(string $directory): array
{
    $f = enrollmentFixture($directory);
    $f[0]->create($f[3]->findById(2), termInput());
    $f[0]->create($f[3]->findById(2), termInput(['code' => '2026-2S', 'start_date' => '2027-01-05', 'end_date' => '2027-05-20']));
    return $f;
}

test('Sections: create normalizes codes, stores approved_count 0, computes remaining, sorts meetings, has no instructors key', function (): void {
    $directory = tempDirectory();
    try {
        [, $sections, , $users] = sectionFixture($directory);
        $s = $sections->create($users->findById(2), sectionInput(['course_code' => ' cs101 ', 'section_code' => ' a ', 'meetings' => [
            meeting('WED', '13:00', '14:00'), meeting('MON', '10:30', '11:30'), meeting('MON', '09:00', '10:30', ' R-2 ')]]));
        same(['id', 'term_id', 'course_code', 'course_title', 'units', 'section_code', 'capacity', 'approved_count', 'remaining',
            'meetings', 'created_at', 'updated_at'], array_keys($s));
        same(['CS101', 'A', 0, 40, 40, 1], [$s['course_code'], $s['section_code'], $s['approved_count'], $s['capacity'], $s['remaining'], $s['id']]);
        same([['day' => 'MON', 'start' => '09:00', 'end' => '10:30', 'room' => 'R-2'],
            ['day' => 'MON', 'start' => '10:30', 'end' => '11:30', 'room' => 'R-101'],
            ['day' => 'WED', 'start' => '13:00', 'end' => '14:00', 'room' => 'R-101']], $s['meetings']);
        check(!array_key_exists('instructors', $s), 'No instructors key in T-20.');
        same($s, $sections->get(1));
    } finally {
        removeTestDirectory($directory);
    }
});

test('Sections: unknown or non-int term_id is 422 term_id; missing and unknown fields are rejected', function (): void {
    $directory = tempDirectory();
    try {
        [, $sections, $repository, $users] = sectionFixture($directory);
        $r = $users->findById(2);
        foreach ([99, 0, -1, '1', 1.0, null, [1], true] as $value) {
            $e = httpFailure(fn () => $sections->create($r, sectionInput(['term_id' => $value])));
            same([422, ['term_id' => 'Unknown term.']], [$e->status, $e->errors]);
        }
        foreach (['term_id', 'course_code', 'course_title', 'units', 'section_code', 'capacity', 'meetings'] as $field) {
            $input = sectionInput();
            unset($input[$field]);
            $e = httpFailure(fn () => $sections->create($r, $input));
            same([422, [$field => 'This field is required.']], [$e->status, $e->errors]);
        }
        foreach (['approved_count' => 3, 'remaining' => 1, 'id' => 5, 'instructors' => [], 'role' => 'admin'] as $field => $value) {
            $e = httpFailure(fn () => $sections->create($r, sectionInput([$field => $value])));
            same([422, [$field => 'Unknown field.']], [$e->status, $e->errors]);
        }
        foreach ([['course_code', ['A', 'bad code', '', 5, str_repeat('A', 21)]], ['section_code', ['', 'a b', str_repeat('A', 11), 1]],
            ['course_title', ['', str_repeat('x', 151), "a\x01b", "a\u{200B}b", 1]]] as [$field, $values]) {
            foreach ($values as $value) {
                $e = httpFailure(fn () => $sections->create($r, sectionInput([$field => $value])));
                check(array_keys($e->errors) === [$field], "{$field}=" . json_encode($value) . ' gave ' . json_encode($e->errors));
            }
        }
        same(['total' => 0], ['total' => $repository->searchSections([], 1, 10)['total']]);
    } finally {
        removeTestDirectory($directory);
    }
});

test('Sections: the (term, course, section) triple is unique (409); other combinations are accepted', function (): void {
    $directory = tempDirectory();
    try {
        [, $sections, $repository, $users] = sectionFixture($directory);
        $r = $users->findById(2);
        $sections->create($r, sectionInput());
        foreach ([sectionInput(), sectionInput(['course_code' => 'cs101', 'section_code' => 'a', 'course_title' => 'Other'])] as $dup) {
            $e = httpFailure(fn () => $sections->create($r, $dup));
            same([409, ['section_code' => 'This section already exists for the course in this term.']], [$e->status, $e->errors]);
        }
        $sections->create($r, sectionInput(['term_id' => 2]));
        $sections->create($r, sectionInput(['section_code' => 'B']));
        $sections->create($r, sectionInput(['course_code' => 'CS102']));
        same(4, $repository->searchSections([], 1, 10)['total']);
    } finally {
        removeTestDirectory($directory);
    }
});

test('Sections: meeting validation (day, time, room, shape, count, overlap)', function (): void {
    $directory = tempDirectory();
    try {
        [, $sections, $repository, $users] = sectionFixture($directory);
        $r = $users->findById(2);
        $keys = static function (mixed $meetings) use ($sections, $r): array {
            return httpFailure(fn () => $sections->create($r, sectionInput(['meetings' => $meetings])))->errors;
        };
        foreach ([['mon', 'meetings.0.day'], ['MONDAY', 'meetings.0.day'], ['Mon', 'meetings.0.day'], ['', 'meetings.0.day']] as [$day, $key]) {
            same([$key], array_keys($keys([meeting($day)])));
        }
        foreach ([['24:00', '10:00', 'start'], ['9:00', '10:00', 'start'], ['09:60', '10:00', 'start'], ['09:00', '9:30', 'end'], ['09:00', '25:00', 'end']] as [$s, $e, $field]) {
            same(["meetings.0.{$field}"], array_keys($keys([meeting('MON', $s, $e)])), "{$s}-{$e}");
        }
        same(['meetings.0.end'], array_keys($keys([meeting('MON', '09:00', '09:00')])));
        same(['meetings.0.end'], array_keys($keys([meeting('MON', '10:00', '09:00')])));
        foreach (['', '   ', str_repeat('r', 31), "R\x01", "R\u{202E}1"] as $room) {
            same(['meetings.0.room'], array_keys($keys([meeting('MON', '09:00', '10:00', $room)])));
        }
        // Non-string time / room types.
        same(['meetings.0.start'], array_keys($keys([(object) ['day' => 'MON', 'start' => 900, 'end' => '10:00', 'room' => 'R']])));
        same(['meetings.0.room'], array_keys($keys([(object) ['day' => 'MON', 'start' => '09:00', 'end' => '10:00', 'room' => 5]])));
        // Shape: extra key, missing key, non-object items, non-list, empty, too many.
        same(['meetings.0'], array_keys($keys([(object) ['day' => 'MON', 'start' => '09:00', 'end' => '10:00', 'room' => 'R', 'x' => 1]])));
        same(['meetings.0'], array_keys($keys([(object) ['day' => 'MON', 'start' => '09:00', 'end' => '10:00']])));
        same(['meetings.0'], array_keys($keys([['MON', '09:00', '10:00', 'R']])));
        same(['meetings.0'], array_keys($keys([['day' => 'MON', 'start' => '09:00', 'end' => '10:00', 'room' => 'R']])), 'assoc arrays are not stdClass');
        same(['meetings.0', 'meetings.1'], array_keys($keys(['MON', 5])));
        foreach ([[], 'MON', null, ['a' => meeting()], (object) ['day' => 'MON'], array_fill(0, 15, meeting())] as $bad) {
            same(['meetings' => 'Provide 1 to 14 meetings.'], $keys($bad));
        }
        // Same-day overlap is 422 on the pair; the overlapping pair is reported by index.
        same(['meetings' => 'Meetings 0 and 1 overlap.'], $keys([meeting('MON', '09:00', '10:30'), meeting('MON', '10:00', '11:00')]));
        same(['meetings' => 'Meetings 0 and 1 overlap.'], $keys([meeting('MON', '09:00', '12:00'), meeting('MON', '10:00', '11:00')]));
        same(['meetings' => 'Meetings 0 and 1 overlap.'], $keys([meeting('MON', '09:00', '10:00'), meeting('MON', '09:00', '10:00')]));
        same(0, $repository->searchSections([], 1, 10)['total']);
        // Accepted: back-to-back, same time on different days, exactly 14, one-minute slots.
        $sections->create($r, sectionInput(['meetings' => [meeting('MON', '09:00', '10:00'), meeting('MON', '10:00', '11:00'),
            meeting('TUE', '09:00', '10:00'), meeting('SUN', '23:58', '23:59')]]));
        $fourteen = [];
        foreach (['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'] as $day) {
            array_push($fourteen, meeting($day, '08:00', '09:00'), meeting($day, '09:00', '10:00'));
        }
        $s = $sections->create($r, sectionInput(['section_code' => 'B', 'meetings' => array_reverse($fourteen)]));
        same(14, count($s['meetings']));
        same(['MON', '08:00'], [$s['meetings'][0]['day'], $s['meetings'][0]['start']]);
        same(['SUN', '09:00'], [$s['meetings'][13]['day'], $s['meetings'][13]['start']]);
    } finally {
        removeTestDirectory($directory);
    }
});

test('Sections: units and capacity must be whole numbers within range', function (): void {
    $directory = tempDirectory();
    try {
        [, $sections, , $users] = sectionFixture($directory);
        $r = $users->findById(2);
        foreach ([0, -1, 1001, '10', 10.0, null, true, [5]] as $value) {
            $e = httpFailure(fn () => $sections->create($r, sectionInput(['capacity' => $value])));
            same([422, ['capacity' => 'Use a whole number from 1 to 1000.']], [$e->status, $e->errors]);
        }
        foreach ([0, 13, -3, '3', 3.5, null] as $value) {
            $e = httpFailure(fn () => $sections->create($r, sectionInput(['units' => $value])));
            same([422, ['units' => 'Use a whole number from 1 to 12.']], [$e->status, $e->errors]);
        }
        $a = $sections->create($r, sectionInput(['capacity' => 1000, 'units' => 12]));
        $b = $sections->create($r, sectionInput(['section_code' => 'B', 'capacity' => 1, 'units' => 1]));
        same([1000, 12, 1, 1], [$a['capacity'], $a['units'], $b['capacity'], $b['units']]);
    } finally {
        removeTestDirectory($directory);
    }
});

test('Sections: capacity cannot drop below approved_count (409) and remaining is computed', function (): void {
    $directory = tempDirectory();
    try {
        [, $sections, $repository, $users] = sectionFixture($directory);
        $r = $users->findById(2);
        $sections->create($r, sectionInput(['capacity' => 10]));
        bumpApprovedCount($directory, 1, 5);
        $e = httpFailure(fn () => $sections->update($r, 1, ['capacity' => 4]));
        same([409, ['capacity' => 'Capacity cannot be below the approved count (5).']], [$e->status, $e->errors]);
        same(10, $repository->findSection(1)->capacity());
        $five = $sections->update($r, 1, ['capacity' => 5]);
        same([5, 5, 0], [$five['capacity'], $five['approved_count'], $five['remaining']]);
        $title = $sections->update($r, 1, ['course_title' => 'Renamed', 'units' => 4]);
        same(['Renamed', 4, 5, 5, 0], [$title['course_title'], $title['units'], $title['capacity'], $title['approved_count'], $title['remaining']]);
        $up = $sections->update($r, 1, ['capacity' => 8]);
        same([8, 5, 3], [$up['capacity'], $up['approved_count'], $up['remaining']]);
        // The stored row never holds the computed field.
        $stored = json_decode((string) file_get_contents($directory . '/enrollment/enrollment.json'), true);
        check(!array_key_exists('remaining', $stored['sections'][0]), 'remaining must not be stored.');
        same(5, $stored['sections'][0]['approved_count']);
        // Range errors still win as 422 (not 409).
        same(422, httpFailure(fn () => $sections->update($r, 1, ['capacity' => 0]))->status);
    } finally {
        removeTestDirectory($directory);
    }
});

test('Sections: PATCH allowlist, merge and replacement semantics, 404, immutable fields', function (): void {
    $directory = tempDirectory();
    try {
        [, $sections, $repository, $users] = sectionFixture($directory);
        $r = $users->findById(2);
        $sections->create($r, sectionInput(['meetings' => [meeting('MON'), meeting('TUE')]]));
        same([422, ['body' => 'Provide at least one field to change.']], (function () use ($sections, $r) {
            $e = httpFailure(fn () => $sections->update($r, 1, []));
            return [$e->status, $e->errors];
        })());
        same(404, httpFailure(fn () => $sections->update($r, 99, ['units' => 2]))->status);
        foreach (['term_id', 'course_code', 'section_code', 'approved_count', 'remaining', 'id', 'instructors'] as $field) {
            $e = httpFailure(fn () => $sections->update($r, 1, [$field => 2]));
            same([422, [$field => 'Unknown field.']], [$e->status, $e->errors]);
        }
        // meetings replace the whole list and are validated like on create.
        $s = $sections->update($r, 1, ['meetings' => [meeting('FRI', '14:00', '15:00')]]);
        same([['day' => 'FRI', 'start' => '14:00', 'end' => '15:00', 'room' => 'R-101']], $s['meetings']);
        same(['meetings' => 'Provide 1 to 14 meetings.'], httpFailure(fn () => $sections->update($r, 1, ['meetings' => []]))->errors);
        same(['meetings' => 'Meetings 0 and 1 overlap.'],
            httpFailure(fn () => $sections->update($r, 1, ['meetings' => [meeting('MON'), meeting('MON')]]))->errors);
        // Untouched fields stay; updated_at is the only other thing that changes.
        same([3, 40, 'A', 'CS101', 1], [$s['units'], $s['capacity'], $s['section_code'], $s['course_code'], $s['term_id']]);
        same(1, count($repository->findSection(1)->toArray()['meetings']));
    } finally {
        removeTestDirectory($directory);
    }
});

test('Sections: list filters, ordering, pagination and strict query validation', function (): void {
    $directory = tempDirectory();
    try {
        [, $sections, , $users] = sectionFixture($directory);
        $r = $users->findById(2);
        same(['sections' => [], 'pagination' => ['page' => 1, 'per_page' => 20, 'total' => 0]], $sections->list([]));
        $sections->create($r, sectionInput(['term_id' => 2, 'course_code' => 'CS101', 'section_code' => 'A']));          // id 1
        $sections->create($r, sectionInput(['course_code' => 'IT-211', 'course_title' => 'Networks Basics', 'section_code' => 'B']));  // id 2
        $sections->create($r, sectionInput(['course_code' => 'CS101', 'section_code' => 'B']));                            // id 3
        $sections->create($r, sectionInput(['course_code' => 'CS101', 'section_code' => 'A']));                            // id 4
        $ids = static fn (array $q): array => array_column($sections->list($q)['sections'], 'id');
        same([4, 3, 2, 1], $ids([]));
        same([4, 3, 2], $ids(['term_id' => '1']));
        same([1], $ids(['term_id' => '2']));
        same([], $ids(['term_id' => '3']));
        same([4, 3, 1], $ids(['course' => ' cs101 ']));
        same([2], $ids(['search' => 'NETWORKS']));
        same([2], $ids(['search' => 'it-2']));
        same([4, 2, 1], $ids(['search' => 'a']));
        same([3, 2], $ids(['search' => 'b']));
        same([4, 3, 1], $ids(['search' => 'cs1']));
        same([4, 3], $ids(['term_id' => '1', 'course' => 'CS101']));
        $page = $sections->list(['per_page' => '2', 'page' => '2']);
        same([2, 1], array_column($page['sections'], 'id'));
        same(['page' => 2, 'per_page' => 2, 'total' => 4], $page['pagination']);
        same([], $ids(['per_page' => '2', 'page' => '3']));
        same(4, $sections->list(['per_page' => '100', 'page' => '1'])['pagination']['total']);
        same([4, 3, 2, 1], $ids(['unknown' => 'x', 'page' => '1']));
        foreach ([['page' => '0'], ['page' => '1000001'], ['page' => 'x'], ['page' => ['1']], ['per_page' => '0'], ['per_page' => '101'],
            ['per_page' => ['5']], ['term_id' => 'abc'], ['term_id' => '0'], ['term_id' => ['1']], ['search' => ['x']],
            ['search' => str_repeat('a', 101)], ['search' => "\xC3\x28"], ['course' => ['x']], ['course' => 'x'], ['course' => 'bad code'], ['course' => '']] as $query) {
            $e = httpFailure(fn () => $sections->list($query));
            same(422, $e->status);
            check(array_keys($e->errors) === [array_key_first($query)], json_encode($query) . ' gave ' . json_encode($e->errors));
        }
        same([4, 3, 2, 1], $ids(['search' => str_repeat(' ', 100)]));
    } finally {
        removeTestDirectory($directory);
    }
});

test('Sections: get on a missing id is 404; student and faculty writes are 403', function (): void {
    $directory = tempDirectory();
    try {
        [, $sections, $repository, $users] = sectionFixture($directory);
        $sections->create($users->findById(1), sectionInput());
        $e = httpFailure(fn () => $sections->get(99));
        same([404, 'Section not found.'], [$e->status, $e->getMessage()]);
        foreach ([3, 4] as $id) {
            $actor = $users->findById($id);
            same(403, httpFailure(fn () => $sections->create($actor, sectionInput(['section_code' => 'Z'])))->status);
            same(403, httpFailure(fn () => $sections->update($actor, 1, ['capacity' => 1]))->status);
        }
        same(1, $repository->searchSections([], 1, 10)['total']);
        same(40, $repository->findSection(1)->capacity());
    } finally {
        removeTestDirectory($directory);
    }
});
