<?php
declare(strict_types=1);

// UNVERIFIED: written without being executed (compromised host, owner forbids running tests).

require_once __DIR__ . '/GradeSupport.php';

/** Starts one worker per job ([op, input, id, actorUserId?]), releases them together, returns the results in job order. */
function raceGradeWorkers(string $directory, array $jobs): array
{
    $workers = [];
    $results = [];
    @unlink($directory . '/start');
    array_map('unlink', glob($directory . '/ready-*') ?: []);
    try {
        foreach ($jobs as $index => $job) {
            $command = [PHP_BINARY, __DIR__ . '/fixtures/grade_worker.php', $directory, $directory . '/start',
                $directory . '/ready-' . $index, $job[0], json_encode((object) $job[1], JSON_THROW_ON_ERROR), (string) $job[2]];
            if (isset($job[3])) {
                $command[] = (string) $job[3];
            }
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            check(is_resource($process), 'Unable to start grade worker.');
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 10;
        while (count(glob($directory . '/ready-*') ?: []) < count($jobs)) {
            check(microtime(true) < $deadline, 'Grade workers did not become ready.');
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

test('Grade concurrency: approve vs finalize never leaves a finalized section with an approved, ungraded, unreported student', function (): void {
    for ($round = 0; $round < 5; $round++) {
        $dir = tempDirectory();
        try {
            $f = gradeFixture($dir, null, [1, 2]);
            gradeSubmitAll($f, [1, 2]);
            $late = $f['enroll']->request($f['cara'], ['section_id' => 1])['id'];           // pending, approved by the race
            [$finalize, $approve] = raceGradeWorkers($dir, [['finalize', [], 1], ['approve', ['status' => 'approved'], $late]]);
            same(true, $approve['ok']);
            $view = $f['service']->sectionGrades($f['admin'], 1);
            $statuses = array_column($view['grades'], 'status');
            if ($finalize['ok']) {
                // finalize won the lock order: the late student goes through the late-approval path and is reported as missing
                same(['finalized', 'finalized'], $statuses);
                same([3], $view['missing']);
            } else {
                // approval committed first: finalize is blocked by it and nothing was finalized
                same([409, [3]], [$finalize['status'], $finalize['errors']['grades_missing'] ?? null]);
                same(['submitted', 'submitted'], $statuses);
                same([3], $view['missing']);
            }
            same([3], array_values(array_diff($f['catalog']->approvedStudentIds(1), [1, 2])));
            same(range(1, count(gradeHistory($dir))), array_column(gradeHistory($dir), 'seq'));
        } finally { removeTestDirectory($dir); }
    }
});

test('Grade concurrency (GRD-15): return-to-draft vs finalize, exactly one wins and the end state is consistent', function (): void {
    for ($round = 0; $round < 5; $round++) {
        $dir = tempDirectory();
        try {
            $f = gradeFixture($dir);
            gradeSubmitAll($f);
            [$finalize, $return] = raceGradeWorkers($dir, [['finalize', [], 1], ['return', ['reason' => 'Recheck'], 1]]);
            same(1, count(array_filter([$finalize, $return], static fn (array $r): bool => $r['ok'])));
            $statuses = array_column($f['service']->sectionGrades($f['admin'], 1)['grades'], 'status');
            $returned = array_filter(gradeHistory($dir), static fn (array $h): bool => $h['action'] === 'returned');
            if ($finalize['ok']) {
                same([409, true], [$return['status'], array_key_exists('status', $return['errors'])]);
                same(['finalized', 'finalized', 'finalized'], $statuses);
                same(0, count($returned));
            } else {
                same([409, true], [$finalize['status'], $finalize['errors'] !== [] ]);
                same(['draft', 'draft', 'draft'], $statuses);
                same(3, count($returned));
            }
            same(range(1, count(gradeHistory($dir))), array_column(gradeHistory($dir), 'seq'));
        } finally { removeTestDirectory($dir); }
    }
});

test('Grade concurrency: two corrections with the same expected_seq, exactly one succeeds and the other is stale', function (): void {
    for ($round = 0; $round < 3; $round++) {
        $dir = tempDirectory();
        try {
            $f = gradeFixture($dir);
            gradeSubmitAll($f);
            $f['service']->finalize($f['admin'], 1);
            $seq = $f['service']->sectionGrades($f['admin'], 1)['grades'][0]['seq'];
            $results = raceGradeWorkers($dir, [
                ['correct', ['value' => '2.00', 'reason' => 'First', 'expected_seq' => $seq], 1],
                ['correct', ['value' => '5.00', 'reason' => 'Second', 'expected_seq' => $seq], 1],
            ]);
            same(1, count(array_filter($results, static fn (array $r): bool => $r['ok'])));
            $loser = array_values(array_filter($results, static fn (array $r): bool => !$r['ok']))[0];
            same(409, $loser['status']);
            check(array_key_exists('stale_grade', $loser['errors']), 'Loser should report stale_grade.');
            $corrected = array_values(array_filter(gradeHistory($dir), static fn (array $h): bool => $h['action'] === 'corrected'));
            same(1, count($corrected));
            same($corrected[0]['new_value'], $f['service']->sectionGrades($f['admin'], 1)['grades'][0]['value']);
        } finally { removeTestDirectory($dir); }
    }
});
