<?php
declare(strict_types=1);

use App\Core\JsonStore;
use App\Students\Repositories\MockStudentRepository;

require_once __DIR__ . '/StudentSupport.php';

/** Starts one worker per job ([op, input, studentId?]), releases them together, returns results. */
function raceStudentWorkers(string $directory, array $jobs): array
{
    $workers = [];
    $results = [];
    @unlink($directory . '/start');
    array_map('unlink', glob($directory . '/ready-*') ?: []);
    try {
        foreach ($jobs as $index => $job) {
            $command = [PHP_BINARY, __DIR__ . '/fixtures/student_worker.php', $directory, $directory . '/start',
                $directory . '/ready-' . $index, $job[0], json_encode($job[1], JSON_THROW_ON_ERROR)];
            if (isset($job[2])) {
                $command[] = (string) $job[2];
            }
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            check(is_resource($process), 'Unable to start student worker.');
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 10;
        while (count(glob($directory . '/ready-*') ?: []) < count($jobs)) {
            check(microtime(true) < $deadline, 'Student workers did not become ready.');
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

test('Students concurrency: simultaneous creates with the same number or the same account yield exactly one profile', function (): void {
    foreach ([
        'same number' => [anaInput(), anaInput(['user_id' => 5]), 'student_number'],
        'same account' => [anaInput(), anaInput(['student_number' => '2026-00002']), 'user_id'],
    ] as $name => [$first, $second, $field]) {
        $directory = tempDirectory();
        try {
            seedStudentRuntime($directory);
            $results = raceStudentWorkers($directory, [['create', $first], ['create', $second], ['create', $second]]);
            $wins = array_values(array_filter($results, static fn (array $r): bool => $r['ok']));
            check(count($wins) === 1, "{$name}: expected one success, got " . json_encode($results));
            foreach (array_filter($results, static fn (array $r): bool => !$r['ok']) as $loser) {
                same(409, $loser['status']);
                check(array_key_exists($field, $loser['errors']), "{$name}: loser should report {$field}");
            }
            same(1, (new MockStudentRepository(new JsonStore($directory . '/students/students.json')))->search([], 1, 10)['total']);
        } finally {
            removeTestDirectory($directory);
        }
    }
});

test('Students concurrency: simultaneous updates of different fields are both kept (no lost update)', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $directory = tempDirectory();
        try {
            [$service, $repository, $users] = studentFixture($directory);
            $service->create($users->findById(2), anaInput());
            $results = raceStudentWorkers($directory, [['update', ['academic_status' => 'on_leave'], 1],
                ['update', ['middle_name' => 'Lopez'], 1], ['update', ['admission_year' => 2025], 1]]);
            same([true, true, true], array_column($results, 'ok'));
            $row = $repository->findById(1)->toArray();
            same(['on_leave', 'Lopez', 2025], [$row['academic_status'], $row['middle_name'], $row['admission_year']]);
        } finally {
            removeTestDirectory($directory);
        }
    }
});

test('Students concurrency: a program change racing a year-level change never leaves an invalid pair', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $directory = tempDirectory();
        try {
            [$service, $repository, $users] = studentFixture($directory);
            $service->create($users->findById(2), anaInput(['year_level' => 2]));
            $results = raceStudentWorkers($directory, [['update', ['program_code' => 'ASCT'], 1], ['update', ['year_level' => 4], 1]]);
            $row = $repository->findById(1)->toArray();
            $max = $row['program_code'] === 'ASCT' ? 2 : 4;
            check($row['year_level'] <= $max, 'Invalid pair stored: ' . json_encode([$row, $results]));
            check(count(array_filter($results, static fn (array $r): bool => $r['ok'])) >= 1, 'At least one update must succeed.');
            foreach (array_filter($results, static fn (array $r): bool => !$r['ok']) as $loser) {
                check(in_array($loser['status'], [409, 422], true), 'Unexpected status ' . $loser['status']);
            }
        } finally {
            removeTestDirectory($directory);
        }
    }
});

test('Academic history concurrency: every concurrent tracked change gets exactly one gap-free history entry', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $directory = tempDirectory();
        try {
            [$service, $repository, $users] = studentFixture($directory);
            $service->create($users->findById(2), anaInput(['year_level' => 1]));
            $results = raceStudentWorkers($directory, [['update', ['academic_status' => 'on_leave'], 1],
                ['update', ['year_level' => 2], 1], ['update', ['program_code' => 'ASCT'], 1], ['update', ['first_name' => 'Anna'], 1]]);
            same([true, true, true, true], array_column($results, 'ok'));
            $entries = $repository->history(1, 1, 100)['entries'];
            same([1, 2, 3, 4, 5, 6], array_column($entries, 'seq'));
            $changes = array_map(static fn (array $e): string => $e['field'] . '=' . json_encode($e['to']), array_slice($entries, 3));
            sort($changes);
            same(['academic_status="on_leave"', 'program_code="ASCT"', 'year_level=2'], $changes);
            same([2, 2, 2], array_column(array_slice($entries, 3), 'actor_id'));
        } finally {
            removeTestDirectory($directory);
        }
    }
});

test('Students storage failure: a write that cannot be committed leaves the store unchanged', function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        return; // Uses Windows open-file replacement semantics (see StorageTest).
    }
    $directory = tempDirectory();
    $holder = null;
    try {
        [$service, $repository, $users] = studentFixture($directory);
        $service->create($users->findById(2), anaInput());
        $before = file_get_contents($directory . '/students/students.json');
        $holder = holdFileOpen($directory . '/students/students.json', 4000, $directory . '/ready-hold');
        same('Unable to replace store.', throws(RuntimeException::class,
            fn () => $service->update($users->findById(2), 1, ['academic_status' => 'graduated']))->getMessage());
        same('Unable to replace store.', throws(RuntimeException::class,
            fn () => $service->create($users->findById(2), anaInput(['user_id' => 5, 'student_number' => '2026-00002'])))->getMessage());
        proc_close($holder);
        $holder = null;
        same($before, file_get_contents($directory . '/students/students.json'));
        // Profile and history rolled back together: still only the 3 creation entries.
        same(3, $repository->history(1, 1, 100)['total']);
        same('active', $repository->findById(1)->toArray()['academic_status']);
    } finally {
        if (is_resource($holder)) {
            proc_close($holder);
        }
        removeTestDirectory($directory);
    }
});
