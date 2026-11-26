<?php
declare(strict_types=1);

require_once __DIR__ . '/FacultySupport.php';

function facultyWinners(array $results): int
{
    return count(array_filter($results, static fn (array $r): bool => $r['ok']));
}

/** Same barrier pattern as the Team 4 workers, with the Team 5 worker script. */
function raceFacultyWorkers(string $directory, array $jobs): array
{
    $workers = [];
    $results = [];
    @unlink($directory . '/start');
    array_map('unlink', glob($directory . '/ready-*') ?: []);
    try {
        foreach ($jobs as $index => $job) {
            $command = [PHP_BINARY, __DIR__ . '/fixtures/faculty_worker.php', $directory, $directory . '/start',
                $directory . '/ready-' . $index, $job[0], json_encode($job[1], JSON_THROW_ON_ERROR)];
            if (isset($job[2])) {
                $command[] = (string) $job[2];
            }
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            check(is_resource($process), 'Unable to start faculty worker.');
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 10;
        while (count(glob($directory . '/ready-*') ?: []) < count($jobs)) {
            check(microtime(true) < $deadline, 'Faculty workers did not become ready.');
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
                if (is_resource($pipe)) { fclose($pipe); }
            }
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        }
    }
}

test('Faculty concurrency F-C01: one employee number, distinct accounts, yields one profile', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $dir = tempDirectory();
        try {
            $f = facultyFixture($dir);
            $results = raceFacultyWorkers($dir, [['create', facultyInput(['user_id' => 3])], ['create', facultyInput(['user_id' => 6, 'employee_number' => 'emp-001'])],
                ['create', facultyInput(['user_id' => 7, 'employee_number' => ' EMP-001 '])]]);
            same(1, facultyWinners($results));
            foreach (array_filter($results, static fn (array $r): bool => !$r['ok']) as $loser) {
                same(409, $loser['status']);
                check(array_key_exists('employee_number', (array) $loser['errors']), 'Loser should report employee_number.');
            }
            same(1, $f['repo']->search([], 1, 10)['total']);
            same(2, count(json_decode(facultyStoreBytes($dir), true)['history']));
        } finally { removeTestDirectory($dir); }
    }
});

test('Faculty concurrency F-C02: one account, distinct numbers, yields one profile', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $dir = tempDirectory();
        try {
            $f = facultyFixture($dir);
            $results = raceFacultyWorkers($dir, [['create', facultyInput(['employee_number' => 'EMP-001'])], ['create', facultyInput(['employee_number' => 'EMP-002'])],
                ['create', facultyInput(['employee_number' => 'EMP-003'])]]);
            same(1, facultyWinners($results));
            foreach (array_filter($results, static fn (array $r): bool => !$r['ok']) as $loser) {
                same(409, $loser['status']);
                check(array_key_exists('user_id', (array) $loser['errors']), 'Loser should report user_id.');
            }
            same(1, $f['repo']->search([], 1, 10)['total']);
        } finally { removeTestDirectory($dir); }
    }
});

test('Faculty concurrency F-C03: concurrent PATCHes keep a gap-free replay-valid history', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $dir = tempDirectory();
        try {
            $f = facultyFixture($dir);
            $f['service']->create($f['users']->findById(2), facultyInput());
            $results = raceFacultyWorkers($dir, [['update', ['status' => 'inactive'], 1], ['update', ['department' => 'Math'], 1],
                ['update', ['status' => 'active'], 1], ['update', ['department' => 'Physics'], 1],
                ['update', ['status' => 'inactive', 'department' => 'Art'], 1], ['update', ['status' => 'active'], 1]]);
            same(6, facultyWinners($results));
            $store = json_decode(facultyStoreBytes($dir), true);
            same(range(1, count($store['history'])), array_column($store['history'], 'seq'));
            // reading validates the replay and that the last entry per field equals the profile value
            $profile = $f['repo']->findById(1)->toArray();
            foreach (['status', 'department'] as $field) {
                $entries = array_values(array_filter($store['history'], static fn (array $e): bool => $e['field'] === $field));
                same($profile[$field], end($entries)['to']);
            }
        } finally { removeTestDirectory($dir); }
    }
});

test('Faculty concurrency F-C04: deactivation racing an assignment ends in an allowed, consistent state', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $dir = tempDirectory();
        try {
            $f = facultyFixture($dir);
            facultyProfiles($f);
            $registrar = $f['users']->findById(2);
            $f['terms']->create($registrar, termInput());
            $f['sections']->create($registrar, sectionInput());
            $results = raceFacultyWorkers($dir, [['update', ['status' => 'inactive'], 2], ['assign', ['faculty_id' => 2], 1]]);
            same(true, $results[0]['ok']);
            check($results[1]['ok'] || $results[1]['status'] === 422, 'Assign must be 201 or 422.');
            // both stores validate; the A4 window itself cannot be forced or excluded here
            $f['repo']->findById(2);
            $f['catalog']->assignedFacultyIds([1]);
            same([], $f['section_directory']->sectionIdsForFaculty(2));
            same(422, httpFailure(fn () => $f['sections']->assignInstructor($registrar, 1, ['faculty_id' => 2]))->status);
        } finally { removeTestDirectory($dir); }
    }
});
