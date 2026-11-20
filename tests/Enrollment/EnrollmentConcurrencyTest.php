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
            foreach ([10, 11, 12] as $id) { $sections->assignInstructor($actor, 1, assignInput($id)); }
            $results = raceEnrollmentWorkers($dir, [['unassign', assignInput(10), 1], ['unassign', assignInput(11), 1],
                ['unassign', assignInput(12), 1], ['assign', assignInput(20), 1], ['assign', assignInput(21), 1], ['assign', assignInput(22), 1]]);
            same(6, raceWinners($results));
            $store = json_decode(storeBytes($dir), true);
            same(range(1, 9), array_column($store['assignment_history'], 'seq'));
            $remaining = $repository->assignedFacultyIds([1])[1];
            sort($remaining);
            same([20, 21, 22], $remaining);
        } finally { removeTestDirectory($dir); }
    }
});
