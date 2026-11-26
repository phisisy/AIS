<?php
declare(strict_types=1);

use App\Enrollment\Repositories\MySQLCatalogRepository;
use App\Students\Repositories\MySQLStudentRepository;

require_once __DIR__ . '/MySQLConcurrencySupport.php';

/** A separate bounded process run for the deadlock and held-timeout faults. */
function mysqlFaultWorkers(array $jobs, int $lockWait, ?PDO $owner = null, ?string $heldLock = null): array
{
    $dir = tempDirectory();
    $workers = [];
    try {
        if ($owner !== null && $heldLock !== null) {
            $owner->beginTransaction();
            $lock = $owner->prepare('SELECT last_seq FROM repository_locks WHERE name = ? FOR UPDATE');
            $lock->execute([$heldLock]);
            check($lock->fetchColumn() !== false, 'Missing held lock.');
        }
        foreach ($jobs as $i => $job) {
            if (str_starts_with($job['op'], 'deadlock_')) {
                $job['phase_ready'] = $dir . '/phase-ready-' . $i;
                $job['phase_start'] = $dir . '/phase-start';
            }
            $out = $dir . '/out-' . $i;
            $err = $dir . '/err-' . $i;
            $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/mysql_worker.php', $dir . '/start',
                $dir . '/ready-' . $i, $dir . '/attempt-' . $i,
                json_encode($job, JSON_THROW_ON_ERROR), (string) $lockWait],
                [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
                $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
            check(is_resource($process), 'Unable to start fault worker.');
            fclose($pipes[0]);
            $workers[] = [$process, $out, $err];
        }
        mysqlWaitForFlags($dir, 'ready-', count($jobs), 10);
        file_put_contents($dir . '/start', 'go');
        mysqlWaitForFlags($dir, 'attempt-', count($jobs), 10);
        if ($heldLock === null) {
            mysqlWaitForFlags($dir, 'phase-ready-', count($jobs), 10);
            file_put_contents($dir . '/phase-start', 'go');
        }
        $results = mysqlCollectWorkers($workers, 15);
        mysqlRecordCompletionOrder($results, $heldLock ?? 'deadlock');
        return $results;
    } finally {
        if ($owner !== null && $owner->inTransaction()) {
            $owner->rollBack();
        }
        foreach ($workers as [$process]) {
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) { proc_terminate($process); }
                proc_close($process);
            }
        }
        removeTestDirectory($dir);
    }
}

if (mysqlConcurrencyEnabled()) {
    test('MySQL T1: injected mid-operation failures roll back all affected tables', static function (): void {
        for ($round = 0; $round < 3; $round++) {
            $owner = mysqlConcurrencyFixture();
            try {
                $result = mysqlRace($owner, 'auth', [[
                    'op' => 'auth_fail_after_audit', 'id' => 3, 'changes' => ['status' => 'inactive'],
                ]]);
                same(0, mysqlWinnerCount($result));
                same('active', $owner->query('SELECT status FROM users WHERE id = 3')->fetchColumn());
                same(0, (int) $owner->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn());
                same(0, (int) $owner->query("SELECT last_seq FROM repository_locks WHERE name = 'auth'")->fetchColumn());

                $app = MySQLTestSupport::connectApp();
                $students = new MySQLStudentRepository($app);
                $student = $students->create(['user_id' => 3, 'student_number' => '2024/003',
                    'first_name' => 'First', 'middle_name' => null, 'last_name' => 'Last',
                    'program_code' => 'BSCS', 'year_level' => 1, 'academic_status' => 'active',
                    'admission_year' => 2024], '2025-01-01T03:04:05Z');
                $id = $student->id();
                $owner->prepare('INSERT INTO student_academic_history (student_id, seq, at, field, from_value, to_value, actor_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$id, 4294967295, '2025-01-01 03:04:05', 'year_level', '1', '1', 0]);
                same('Student repository unavailable.', throws(RuntimeException::class,
                    static fn () => $students->update($id, ['year_level' => 2], '2025-01-02T03:04:05Z'))->getMessage());
                same(1, (int) $owner->query('SELECT year_level FROM students WHERE id = ' . $id)->fetchColumn());
                same(4, (int) $owner->query('SELECT COUNT(*) FROM student_academic_history WHERE student_id = ' . $id)->fetchColumn());

                $catalog = new MySQLCatalogRepository($app);
                $term = $catalog->createTerm(['code' => '2025-A', 'name' => 'Term', 'start_date' => '2025-01-01',
                    'end_date' => '2025-05-01', 'is_current' => false, 'enrollment_open' => false], '2025-01-01T03:04:05Z');
                $section = $catalog->createSection(['term_id' => $term->id(), 'course_code' => 'CS101',
                    'course_title' => 'Course', 'units' => 3, 'section_code' => 'A', 'capacity' => 10,
                    'delivery_mode' => 'scheduled', 'meetings' => [['day' => 'MON', 'start' => '09:00', 'end' => '10:00', 'room' => 'R101']]],
                    '2025-01-01T03:04:05Z');
                same('Catalog repository unavailable.', throws(RuntimeException::class,
                    static fn () => $catalog->assignInstructor($section->id(), 999, '2025-01-02T03:04:05Z', 1))->getMessage());
                same(0, (int) $owner->query('SELECT COUNT(*) FROM section_instructor_history')->fetchColumn());
                same(0, (int) $owner->query('SELECT COUNT(*) FROM section_instructors')->fetchColumn());
                same(0, (int) $owner->query("SELECT last_seq FROM repository_locks WHERE name = 'enrollment'")->fetchColumn());
                $app = null;
            } finally { $owner = null; }
        }
    });

    test('MySQL T2: commit and rollback both release the auth lock', static function (): void {
        for ($round = 0; $round < 3; $round++) {
            foreach ([false, true] as $rollback) {
                $owner = mysqlConcurrencyFixture();
                try {
                    $op = $rollback ? 'auth_rollback_then_write' : 'auth_commit_then_write';
                    same(1, mysqlWinnerCount(mysqlRace($owner, 'auth', [['op' => $op]], 2)));
                    same($rollback ? 'race3@example.test' : 'first-write@example.test',
                        $owner->query('SELECT email FROM users WHERE id = 3')->fetchColumn());
                    same('inactive', $owner->query('SELECT status FROM users WHERE id = 5')->fetchColumn());
                    same($rollback ? [1] : [1, 2], mysqlSequence($owner, 'SELECT id FROM audit_logs ORDER BY id'));
                } finally { $owner = null; }
            }
        }
    });

    test('MySQL T3: one cross-store deadlock victim rolls back and survivor commits', static function (): void {
        for ($round = 0; $round < 3; $round++) {
            $owner = mysqlConcurrencyFixture();
            try {
                $app = MySQLTestSupport::connectApp();
                (new MySQLStudentRepository($app))->create(['user_id' => 3, 'student_number' => '2024/003',
                    'first_name' => 'First', 'middle_name' => null, 'last_name' => 'Last',
                    'program_code' => 'BSCS', 'year_level' => 1, 'academic_status' => 'active',
                    'admission_year' => 2024], '2025-01-01T03:04:05Z');
                $app = null;
                $results = mysqlFaultWorkers([['op' => 'deadlock_auth'], ['op' => 'deadlock_student']], 5);
                same(1, mysqlWinnerCount($results));
                $victim = array_values(array_filter($results, static fn (array $r): bool => !$r['ok']))[0];
                same([RuntimeException::class, 1213], [$victim['class'], $victim['driver_code'] ?? null]);
                check(in_array($victim['message'], ['User repository unavailable.', 'Student repository unavailable.'], true), 'Unsanitized deadlock failure.');
                $authCommitted = $results[0]['ok'];
                same($authCommitted ? 'deadlock-auth@example.test' : 'race3@example.test',
                    $owner->query('SELECT email FROM users WHERE id = 3')->fetchColumn());
                same($authCommitted ? 1 : 2, (int) $owner->query('SELECT year_level FROM students WHERE id = 1')->fetchColumn());
                same($authCommitted ? [1] : [], mysqlSequence($owner, 'SELECT id FROM audit_logs ORDER BY id'));
                same($authCommitted ? [1, 2, 3] : [1, 2, 3, 4],
                    mysqlSequence($owner, 'SELECT seq FROM student_academic_history WHERE student_id = 1 ORDER BY seq'));
            } finally { $owner = null; }
        }
    });

    test('MySQL T4: lock timeout is sanitized and writes nothing', static function (): void {
        for ($round = 0; $round < 3; $round++) {
            $owner = mysqlConcurrencyFixture();
            try {
                $results = mysqlFaultWorkers([[
                    'op' => 'auth_timeout_probe', 'id' => 3, 'changes' => ['status' => 'inactive'],
                ]], 1, $owner, 'auth');
                same(0, mysqlWinnerCount($results));
                same([RuntimeException::class, 'User repository unavailable.'],
                    [$results[0]['class'], $results[0]['message']]);
                same(1205, $results[0]['driver_code'] ?? null);
                same('active', $owner->query('SELECT status FROM users WHERE id = 3')->fetchColumn());
                same(0, (int) $owner->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn());
                same(0, (int) $owner->query("SELECT last_seq FROM repository_locks WHERE name = 'auth'")->fetchColumn());
            } finally { $owner = null; }
        }
    });
}
