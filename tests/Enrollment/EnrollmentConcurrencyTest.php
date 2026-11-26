<?php
declare(strict_types=1);

require_once __DIR__ . '/EnrollmentSupport.php';

/** Starts one worker per job ([op, input, id?]), releases them together, returns results. */
function raceEnrollmentWorkers(string $directory, array $jobs): array
{
    $workers = [];
    $results = [];
    @unlink($directory . '/start');
    array_map('unlink', glob($directory . '/ready-*') ?: []);
    try {
        foreach ($jobs as $index => $job) {
            $command = [PHP_BINARY, __DIR__ . '/fixtures/enrollment_worker.php', $directory, $directory . '/start',
                $directory . '/ready-' . $index, $job[0], json_encode($job[1], JSON_THROW_ON_ERROR)];
            if (isset($job[2])) {
                $command[] = (string) $job[2];
            }
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            check(is_resource($process), 'Unable to start enrollment worker.');
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 10;
        while (count(glob($directory . '/ready-*') ?: []) < count($jobs)) {
            check(microtime(true) < $deadline, 'Enrollment workers did not become ready.');
            usleep(5000);
        }
        file_put_contents($directory . '/start', 'go');
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            same(0, proc_close($process));
            same('', $errors);
            $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        }
        $workers = [];
        return $results;
    } finally {
        foreach ($workers as [$process, $pipes]) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
}

function raceWinners(array $results): int
{
    return count(array_filter($results, static fn (array $r): bool => $r['ok']));
}

test('Enrollment concurrency C1: simultaneous creates of one term code yield exactly one term', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $directory = tempDirectory();
        try {
            [, , $repository] = enrollmentFixture($directory);
            $results = raceEnrollmentWorkers($directory, [['createTerm', termInput()], ['createTerm', termInput(['code' => '2026-1s'])],
                ['createTerm', termInput(['code' => ' 2026-1S '])]]);
            same(1, raceWinners($results));
            foreach (array_filter($results, static fn (array $r): bool => !$r['ok']) as $loser) {
                same(409, $loser['status']);
                check(array_key_exists('code', $loser['errors']), 'Loser should report code.');
            }
            same(1, count($repository->terms()));
        } finally {
            removeTestDirectory($directory);
        }
    }
});

test('Enrollment concurrency C2: concurrent is_current switches leave exactly one current term', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $directory = tempDirectory();
        try {
            [$terms, , $repository, $users] = enrollmentFixture($directory);
            foreach (['T1', 'T2', 'T3'] as $code) {
                $terms->create($users->findById(2), termInput(['code' => $code]));
            }
            $results = raceEnrollmentWorkers($directory, [['updateTerm', ['is_current' => true], 1], ['updateTerm', ['is_current' => true], 2],
                ['updateTerm', ['is_current' => true], 3]]);
            same([true, true, true], array_column($results, 'ok'));
            same(1, count(array_filter($repository->terms(), static fn ($t): bool => $t->isCurrent())), 'Exactly one current term.');
        } finally {
            removeTestDirectory($directory);
        }
    }
});

test('Enrollment concurrency C3: simultaneous creates of one section triple yield exactly one section', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $directory = tempDirectory();
        try {
            [$terms, , $repository, $users] = enrollmentFixture($directory);
            $terms->create($users->findById(2), termInput());
            $body = ['term_id' => 1, 'course_code' => 'CS101', 'course_title' => 'Intro', 'units' => 3, 'section_code' => 'A', 'capacity' => 30,
                'meetings' => [['day' => 'MON', 'start' => '09:00', 'end' => '10:00', 'room' => 'R-1']]];
            $results = raceEnrollmentWorkers($directory, [['createSection', $body], ['createSection', array_replace($body, ['section_code' => 'a'])],
                ['createSection', $body]]);
            same(1, raceWinners($results));
            foreach (array_filter($results, static fn (array $r): bool => !$r['ok']) as $loser) {
                same(409, $loser['status']);
            }
            same(1, $repository->searchSections([], 1, 10)['total']);
            // Distinct triples race fine and get distinct, dense ids.
            $results = raceEnrollmentWorkers($directory, [['createSection', array_replace($body, ['section_code' => 'B'])],
                ['createSection', array_replace($body, ['section_code' => 'C'])], ['createSection', array_replace($body, ['section_code' => 'D'])]]);
            same([true, true, true], array_column($results, 'ok'));
            $ids = array_map(static fn ($s): int => $s->id(), $repository->searchSections([], 1, 10)['sections']);
            sort($ids);
            same([1, 2, 3, 4], $ids);
        } finally {
            removeTestDirectory($directory);
        }
    }
});

test('Enrollment concurrency C4: capacity reductions racing approved_count bumps never leave capacity below approved_count', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $directory = tempDirectory();
        try {
            [$terms, $sections, $repository, $users] = enrollmentFixture($directory);
            $terms->create($users->findById(2), termInput());
            $sections->create($users->findById(2), sectionInput(['capacity' => 10]));
            bumpApprovedCount($directory, 1, 5);
            $results = raceEnrollmentWorkers($directory, [['updateSection', ['capacity' => 6], 1], ['bump', [], 1], ['bump', [], 1],
                ['updateSection', ['capacity' => 5], 1], ['bump', [], 1]]);
            $section = $repository->findSection(1);
            check($section->capacity() >= $section->approvedCount(), 'capacity ' . $section->capacity() . ' < approved ' . $section->approvedCount());
            foreach ($results as $i => $r) {
                if (!$r['ok']) {
                    same(409, $r['status']);
                }
            }
            // Every successful bump is reflected in the stored count.
            same(5 + raceWinners([$results[1], $results[2], $results[4]]), $section->approvedCount());
        } finally {
            removeTestDirectory($directory);
        }
    }
});

test('Enrollment concurrency C5: concurrent PATCHes of different section fields are all kept (no lost update)', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $directory = tempDirectory();
        try {
            [$terms, $sections, $repository, $users] = enrollmentFixture($directory);
            $terms->create($users->findById(2), termInput());
            $sections->create($users->findById(2), sectionInput());
            $meetings = [['day' => 'TUE', 'start' => '13:00', 'end' => '14:30', 'room' => 'R-9']];
            $results = raceEnrollmentWorkers($directory, [['updateSection', ['course_title' => 'Renamed'], 1], ['updateSection', ['units' => 5], 1],
                ['updateSection', ['meetings' => $meetings], 1], ['updateSection', ['capacity' => 12], 1]]);
            same([true, true, true, true], array_column($results, 'ok'));
            $row = $repository->findSection(1)->toArray();
            same(['Renamed', 5, 12, $meetings], [$row['course_title'], $row['units'], $row['capacity'], $row['meetings']]);
            same(['CS101', 'A', 1, 0], [$row['course_code'], $row['section_code'], $row['term_id'], $row['approved_count']]);
        } finally {
            removeTestDirectory($directory);
        }
    }
});

test('Enrollment concurrency: term PATCHes of different fields are all kept', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $directory = tempDirectory();
        try {
            [$terms, , $repository, $users] = enrollmentFixture($directory);
            $terms->create($users->findById(2), termInput());
            $results = raceEnrollmentWorkers($directory, [['updateTerm', ['name' => 'Renamed'], 1], ['updateTerm', ['enrollment_open' => true], 1],
                ['updateTerm', ['end_date' => '2027-01-31'], 1]]);
            same([true, true, true], array_column($results, 'ok'));
            $row = $repository->findTerm(1)->toArray();
            same(['Renamed', true, '2027-01-31', '2026-08-10'], [$row['name'], $row['enrollment_open'], $row['end_date'], $row['start_date']]);
        } finally {
            removeTestDirectory($directory);
        }
    }
});

test('Enrollment concurrency C6: overlapping assignments serialize with one conflict', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $dir = tempDirectory();
        try {
            [$terms, $sections, $repository, $users] = enrollmentFixture($dir);
            $actor = $users->findById(2);
            $terms->create($actor, termInput());
            $sections->create($actor, sectionInput(['meetings' => [meeting('MON', '09:00', '10:00')]]));
            $sections->create($actor, sectionInput(['section_code' => 'B', 'meetings' => [meeting('MON', '09:30', '10:30')]]));
            $results = raceEnrollmentWorkers($dir, [['assign', assignInput(), 1], ['assign', assignInput(), 2]]);
            same(1, raceWinners($results));
            foreach ($results as $result) { if (!$result['ok']) { same([409, ['faculty_id']], [$result['status'], array_keys($result['errors'])]); } }
            same(1, count($repository->sectionIdsForFaculty(10)));
            same(1, count(json_decode(storeBytes($dir), true)['assignment_history']));
        } finally { removeTestDirectory($dir); }
    }
});

test('Enrollment concurrency C7 and C8: cap and duplicate races append once', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $dir = tempDirectory();
        try {
            [$terms, $sections, $repository, $users] = enrollmentFixture($dir,
                new StubFacultyIdentity(array_fill_keys(range(10, 99), ['name' => 'Instructor', 'active' => true])));
            $actor = $users->findById(2);
            $terms->create($actor, termInput());
            $sections->create($actor, sectionInput());
            foreach (range(10, 13) as $id) { $sections->assignInstructor($actor, 1, assignInput($id)); }
            $results = raceEnrollmentWorkers($dir, [['assign', assignInput(14), 1], ['assign', assignInput(15), 1], ['assign', assignInput(16), 1]]);
            same(1, raceWinners($results));
            foreach ($results as $result) { if (!$result['ok']) { same([409, ['instructors']], [$result['status'], array_keys($result['errors'])]); } }
            same(5, count($repository->assignedFacultyIds([1])[1]));
            $sections->create($actor, sectionInput(['section_code' => 'B', 'meetings' => [meeting('TUE')]]));
            $results = raceEnrollmentWorkers($dir, [['assign', assignInput(20), 2], ['assign', assignInput(20), 2], ['assign', assignInput(20), 2]]);
            same(1, raceWinners($results));
            foreach ($results as $result) { if (!$result['ok']) { same([409, ['faculty_id']], [$result['status'], array_keys($result['errors'])]); } }
            same([20], $repository->assignedFacultyIds([2])[2]);
            same(6, count(json_decode(storeBytes($dir), true)['assignment_history']));
        } finally { removeTestDirectory($dir); }
    }
});

test('Enrollment concurrency C9: assignment and timetable PATCH cannot both create a conflict', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $dir = tempDirectory();
        try {
            [$terms, $sections, $repository, $users] = enrollmentFixture($dir,
                new StubFacultyIdentity([10 => ['name' => 'Instructor', 'active' => true]]));
            $actor = $users->findById(2);
            $terms->create($actor, termInput());
            $sections->create($actor, sectionInput(['meetings' => [meeting('MON', '09:00', '10:00')]]));
            $sections->create($actor, sectionInput(['section_code' => 'B', 'meetings' => [meeting('MON', '10:00', '11:00')]]));
            $sections->assignInstructor($actor, 1, assignInput());
            $results = raceEnrollmentWorkers($dir, [['assign', assignInput(), 2],
                ['updateSection', ['meetings' => [['day' => 'MON', 'start' => '09:30', 'end' => '10:30', 'room' => 'R']]], 2]]);
            same(1, raceWinners($results));
            foreach ($results as $result) { if (!$result['ok']) { same(409, $result['status']); } }
            $repository->findSection(2); // Snapshot must accept the final store.
        } finally { removeTestDirectory($dir); }
    }
});

test('Enrollment concurrency C10: mixed writes keep gap-free history and replay', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $dir = tempDirectory();
        try {
            [$terms, $sections, $repository, $users] = enrollmentFixture($dir,
                new StubFacultyIdentity(array_fill_keys(range(10, 99), ['name' => 'Instructor', 'active' => true])));
            $actor = $users->findById(2);
            $terms->create($actor, termInput());
            $sections->create($actor, sectionInput());
            $sections->create($actor, sectionInput(['section_code' => 'B', 'meetings' => [meeting('TUE')]]));
            foreach ([10, 11, 12] as $id) { $sections->assignInstructor($actor, 1, assignInput($id)); }
            $results = raceEnrollmentWorkers($dir, [['unassign', assignInput(10), 1], ['unassign', assignInput(11), 1],
                ['unassign', assignInput(12), 1], ['assign', assignInput(20), 2], ['assign', assignInput(21), 2], ['assign', assignInput(22), 2]]);
            same(6, raceWinners($results));
            $store = json_decode(storeBytes($dir), true);
            same(range(1, 9), array_column($store['assignment_history'], 'seq'));
            $assigned = $repository->assignedFacultyIds([1, 2]); // Snapshot must replay the final store.
            sort($assigned[2]);
            same([], $assigned[1]);
            same([20, 21, 22], $assigned[2]);
        } finally { removeTestDirectory($dir); }
    }
});

test('Enrollment concurrency C11: assign and unassign of one pair serialize with matching history', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $dir = tempDirectory();
        try {
            [$terms, $sections, $repository, $users] = enrollmentFixture($dir,
                new StubFacultyIdentity([10 => ['name' => 'Instructor', 'active' => true]]));
            $actor = $users->findById(2);
            $terms->create($actor, termInput());
            $sections->create($actor, sectionInput());
            $results = raceEnrollmentWorkers($dir, [['assign', assignInput(10), 1], ['unassign', assignInput(10), 1]]);
            same(true, $results[0]['ok']);
            $history = json_decode(storeBytes($dir), true)['assignment_history'];
            same(range(1, raceWinners($results)), array_column($history, 'seq'));
            if ($results[1]['ok']) {
                same(2, raceWinners($results));
                same(['assigned', 'unassigned'], array_column($history, 'action'));
                same([], $repository->assignedFacultyIds([1])[1]);
            } else {
                same(1, raceWinners($results));
                same([404, 'Instructor assignment not found.'], [$results[1]['status'], $results[1]['message']]);
                same(['assigned'], array_column($history, 'action'));
                same([10], $repository->assignedFacultyIds([1])[1]);
            }
            same(array_fill(0, count($history), 1), array_column($history, 'section_id'));
            same(array_fill(0, count($history), 10), array_column($history, 'faculty_id'));
        } finally { removeTestDirectory($dir); }
    }
});

test('Enrollment concurrency C12: three approvals for the last seat yield exactly one winner and a consistent count', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $dir = tempDirectory();
        try {
            [$service, $repository, $users] = requestFixture($dir);
            foreach (array_slice(t22Actors($users), 0, 3) as $student) {
                $service->request($student, ['section_id' => 2]); // capacity 1; enrollments 1..3
            }
            $results = raceEnrollmentWorkers($dir, [['decide', ['status' => 'approved'], 1], ['decide', ['status' => 'approved'], 2],
                ['decide', ['status' => 'approved'], 3]]);
            same(1, raceWinners($results));
            foreach (array_filter($results, static fn (array $r): bool => !$r['ok']) as $loser) {
                same(409, $loser['status']);
            }
            same([1, 1], [$repository->findSection(2)->approvedCount(), count($repository->approvedStudentIds(2))]);
        } finally { removeTestDirectory($dir); }
    }
});

test('Enrollment concurrency C13: duplicate requests and clashing requests from one student admit exactly one', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $dir = tempDirectory();
        try {
            [, $repository] = requestFixture($dir);
            $same = ['enroll', ['user' => 4, 'section_id' => 1]];
            $results = raceEnrollmentWorkers($dir, [$same, $same, $same]);
            same(1, raceWinners($results));
            foreach (array_filter($results, static fn (array $r): bool => !$r['ok']) as $loser) {
                same(409, $loser['status']);
            }
            same(1, $repository->searchEnrollments(['student_id' => 1], 1, 10)['total']);
            // Section 3 (TUE) is free for the student; sections 2 and 3's rivals are decided by the lock.
            $results = raceEnrollmentWorkers($dir, [['enroll', ['user' => 6, 'section_id' => 1]], ['enroll', ['user' => 6, 'section_id' => 2]]]);
            same(1, raceWinners($results));
            same(1, $repository->searchEnrollments(['student_id' => 2], 1, 10)['total']);
        } finally { removeTestDirectory($dir); }
    }
});

test('Enrollment concurrency C14: a drop racing an approval always ends dropped with a zero count and a valid history', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $dir = tempDirectory();
        try {
            [$service, $repository, $users] = requestFixture($dir);
            $service->request(t22Actors($users)[0], ['section_id' => 1]);
            $results = raceEnrollmentWorkers($dir, [['decide', ['status' => 'approved'], 1], ['dropEnrollment', ['user' => 4], 1]]);
            same(true, $results[1]['ok']);
            if (!$results[0]['ok']) {
                same(409, $results[0]['status']);
            }
            same(['dropped', 0], [$repository->findEnrollment(1)->status(), $repository->findSection(1)->approvedCount()]);
            $history = json_decode(storeBytes($dir), true)['enrollment_history'];
            same(range(1, count($history)), array_column($history, 'seq'));
        } finally { removeTestDirectory($dir); }
    }
});

test('Enrollment concurrency C15 (OQ-3): an approval racing a meeting PATCH never leaves two overlapping approved classes', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $dir = tempDirectory();
        try {
            [$service, $repository, $users] = requestFixture($dir);
            [$ana, , , $registrar] = t22Actors($users);
            $service->request($ana, ['section_id' => 1]); // MON 09:00-10:30
            $service->request($ana, ['section_id' => 3]); // TUE 09:00-10:00
            $service->decide($registrar, 1, ['status' => 'approved']);
            $results = raceEnrollmentWorkers($dir, [['decide', ['status' => 'approved'], 2],
                ['updateSection', ['meetings' => [['day' => 'TUE', 'start' => '09:30', 'end' => '10:30', 'room' => 'R']]], 1]]);
            same(1, raceWinners($results));
            foreach (array_filter($results, static fn (array $r): bool => !$r['ok']) as $loser) {
                same(409, $loser['status']);
            }
            $approved = $repository->searchEnrollments(['student_id' => 1, 'status' => 'approved'], 1, 10)['total'];
            same($results[0]['ok'] ? 2 : 1, $approved);
        } finally { removeTestDirectory($dir); }
    }
});
