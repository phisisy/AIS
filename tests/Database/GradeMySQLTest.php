<?php
declare(strict_types=1);

// T-32 MySQL tests (UNVERIFIED: written, never run; the owner forbids execution on this host). Online only: they need
// AIS_MYSQL_TESTS=1, the ais_test target, AIS_MYSQL_TEST_APP_PASSWORD and app_grants.sql re-applied after V0010 (no grant change since V0008).
// Parity tests drive MockGradeRepository and MySQLGradeRepository with the same calls and require identical results.

use App\Core\HttpException;
use App\Core\JsonStore;
use App\Enrollment\Repositories\MockCatalogRepository;
use App\Enrollment\Repositories\MySQLCatalogRepository;
use App\Faculty\Models\Grade;
use App\Faculty\Repositories\MockGradeRepository;
use App\Faculty\Repositories\MySQLGradeRepository;
use Database\Tools\IntegrityVerifier;
use Database\Tools\MigrationRunner;

require_once dirname(__DIR__) . '/Enrollment/CatalogRepositoryContract.php';
require_once __DIR__ . '/MySQLTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/tools/MigrationRunner.php';
require_once dirname(__DIR__, 2) . '/database/tools/IntegrityVerifier.php';

/**
 * Fresh schema (V0001-V0010), users 1-6 (student users 3-5), one program, students 1-3, one open term with section 1 (CS101-A, capacity 10)
 * on BOTH a MySQL and a Mock catalog (same ids). Students in $approve get an approved enrollment, those in $pending a pending one.
 * Grade repos: MySQL on one app-account PDO shared with its catalog (finalize joins the catalog transaction), Mock on a temp dir.
 */
function gradeMysql(array $approve = [1, 2, 3], array $pending = []): array
{
    $owner = MySQLTestSupport::connect();
    MySQLTestSupport::reset($owner);
    (new MigrationRunner($owner, 'ais_test', dirname(__DIR__, 2) . '/database/migrations'))->up();
    $user = $owner->prepare('INSERT INTO users (id, email, status, password_hash, auth_version) VALUES (?, ?, ?, ?, ?)');
    $hash = password_hash('T32 grade passphrase', PASSWORD_DEFAULT);
    for ($id = 1; $id <= 6; $id++) {
        $user->execute([$id, "t32user{$id}@example.test", 'active', $hash, 1]);
    }
    $owner->exec("INSERT INTO programs (code, name, max_year_level) VALUES ('BSCS', 'Computer Science', 4)");
    $student = $owner->prepare('INSERT INTO students (id, user_id, student_number, first_name, middle_name, last_name, program_code, '
        . 'year_level, academic_status, admission_year, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)');
    foreach ([1, 2, 3] as $id) {
        $student->execute([$id, $id + 2, "T32-{$id}", 'Student', "Number{$id}", 'BSCS', 1, 'active', 2025, '2025-01-01 03:04:05', '2025-01-01 03:04:05']);
    }
    MySQLTestSupport::completeFixture($owner, [1 => 'registrar', 2 => 'faculty', 3 => 'student', 4 => 'student', 5 => 'student', 6 => 'admin']);
    $dir = tempDirectory();
    $app = MySQLTestSupport::connectApp();
    $sqlCatalog = new MySQLCatalogRepository($app);
    $mockCatalog = new MockCatalogRepository(new JsonStore($dir . '/enrollment.json'));
    $enrollments = [];
    foreach ([$sqlCatalog, $mockCatalog] as $which => $catalog) {
        $term = $catalog->createTerm(catalogTermFields('2025-A', ['enrollment_open' => true]), catalogTime());
        $catalog->createSection(catalogSectionFields($term->id(), 'CS101', 'A'), catalogTime());
        foreach (array_merge($approve, $pending) as $studentId) {
            $enrollment = $catalog->requestEnrollment($studentId, 1, catalogTime(), $studentId + 2);
            if (in_array($studentId, $approve, true)) {
                $catalog->decideEnrollment($enrollment->id(), 'approved', null, catalogTime(), 1);
            }
            $enrollments[$studentId] = $enrollment->id();
        }
    }
    return ['owner' => $owner, 'app' => $app, 'dir' => $dir, 'enrollments' => $enrollments,
        'sqlCatalog' => $sqlCatalog, 'mockCatalog' => $mockCatalog,
        'sql' => new MySQLGradeRepository($app, $sqlCatalog),
        'mock' => new MockGradeRepository(new JsonStore($dir . '/grades.json'), $mockCatalog)];
}

function gradeOnline(): bool
{
    return getenv('AIS_MYSQL_TESTS') === '1' && is_string(getenv('AIS_MYSQL_TEST_APP_PASSWORD')) && MySQLTestSupport::enabled();
}

function gradeNormalize(mixed $value): mixed
{
    return $value instanceof Grade ? $value->toArray() : (is_array($value) ? array_map('gradeNormalize', $value) : $value);
}

/** Runs $op on the Mock and on MySQL, requires identical results or identical failures (HttpException status/message/errors, else class + message). */
function gradeBoth(array $f, callable $op): array
{
    $out = [];
    foreach ([$f['mock'], $f['sql']] as $repo) {
        try {
            $out[] = ['ok', gradeNormalize($op($repo))];
        } catch (HttpException $error) {
            $out[] = ['http', $error->status, $error->getMessage(), $error->errors];
        } catch (Throwable $error) {
            $out[] = ['error', get_class($error), $error->getMessage()];
        }
    }
    same($out[0], $out[1]);
    same(false, $f['app']->inTransaction());
    return $out[0];
}

function gradeHttp(array $result, int $status, ?array $errors = null): void
{
    same('http', $result[0]);
    same($status, $result[1]);
    if ($errors !== null) {
        same($errors, $result[3]);
    }
}

function gradeSqlHistory(PDO $owner): array
{
    return array_map(static fn (array $r): array => ['seq' => (int) $r['seq'], 'at' => str_replace(' ', 'T', $r['at']) . 'Z', 'action' => $r['action'],
        'grade_id' => (int) $r['grade_id'], 'section_id' => (int) $r['section_id'], 'student_id' => (int) $r['student_id'],
        'old_value' => $r['old_value'], 'new_value' => $r['new_value'], 'old_remarks' => $r['old_remarks'], 'new_remarks' => $r['new_remarks'],
        'reason' => $r['reason'], 'actor_id' => (int) $r['actor_id']],
        $owner->query('SELECT seq, at, action, grade_id, section_id, student_id, old_value, new_value, old_remarks, new_remarks, reason, actor_id FROM grade_history ORDER BY seq')->fetchAll(PDO::FETCH_ASSOC));
}

function gradeLastSeq(PDO $owner): int
{
    return (int) $owner->query("SELECT last_seq FROM repository_locks WHERE name = 'grades'")->fetchColumn();
}

function gradeT(int $n): string
{
    return sprintf('2025-01-02T00:00:%02dZ', $n);
}

function gradeVerify(PDO $owner): array
{
    return array_filter((new IntegrityVerifier($owner))->verify(), static fn (array $ids): bool => $ids !== []);
}

/** Starts one worker per job ([op, input]), releases them together and returns their JSON results in job order. */
function gradeMysqlRace(array $jobs): array
{
    $dir = tempDirectory();
    $workers = [];
    try {
        foreach ($jobs as $index => $job) {
            $command = [PHP_BINARY, __DIR__ . '/fixtures/grade_mysql_worker.php', $dir . '/start', $dir . '/ready-' . $index,
                $job[0], json_encode($job[1], JSON_THROW_ON_ERROR)];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
            check(is_resource($process), 'Unable to start grade MySQL worker.');
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 15;
        while (count(glob($dir . '/ready-*') ?: []) < count($jobs)) {
            check(microtime(true) < $deadline, 'Grade MySQL workers did not become ready.');
            usleep(5000);
        }
        file_put_contents($dir . '/start', 'go');
        $results = [];
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
        removeTestDirectory($dir);
    }
}

if (gradeOnline()) {
    test('T-32 MySQL: full lifecycle gives the same results, errors, ordering and history as the mock', static function (): void {
        $f = gradeMysql();
        try {
            $both = static fn (callable $op): array => gradeBoth($f, $op);
            $created = $both(static fn ($r) => $r->saveDraft(1, 1, '1.25', 'note', gradeT(1), 2));
            same([true, 'draft', 1], [$created[1]['created'], $created[1]['grade']['status'], $created[1]['grade']['seq']]);
            $updated = $both(static fn ($r) => $r->saveDraft(1, 1, '2.00', null, gradeT(2), 2));
            same([false, 2], [$updated[1]['created'], $updated[1]['grade']['seq']]);
            $both(static fn ($r) => $r->saveDraft(1, 2, '1.00', null, gradeT(3), 2));
            $both(static fn ($r) => $r->saveDraft(1, 3, '123456789', null, gradeT(3), 2));          // model refuses: nothing written on either side
            gradeHttp($both(static fn ($r) => $r->submitSection(1, [1, 2, 3], gradeT(4), 2)), 409, ['grades_missing' => [3]]);
            $both(static fn ($r) => $r->saveDraft(1, 3, '5.00', 'x', gradeT(5), 2));
            same(3, count($both(static fn ($r) => $r->submitSection(1, [1, 2, 3], gradeT(6), 2))[1]));
            gradeHttp($both(static fn ($r) => $r->submitSection(1, [1, 2, 3], gradeT(7), 2)), 409);
            gradeHttp($both(static fn ($r) => $r->saveDraft(1, 1, '1.00', null, gradeT(8), 2)), 409, ['status' => 'Submitted or finalized grades cannot be edited.']);
            same(3, count($both(static fn ($r) => $r->returnSection(1, 'Check marks.', gradeT(9), 1))[1]));
            gradeHttp($both(static fn ($r) => $r->returnSection(1, 'Again.', gradeT(10), 1)), 409);
            same(['draft', 'draft', 'draft'], array_column($both(static fn ($r) => $r->forSection(1))[1], 'status'));
            $both(static fn ($r) => $r->submitSection(1, [1, 2, 3], gradeT(11), 2));
            gradeHttp($both(static fn ($r) => $r->finalizeSection(1, gradeT(12), 2)), 403);         // SoD (b): the entrant cannot finalize
            same(3, count($both(static fn ($r) => $r->finalizeSection(1, gradeT(13), 1))[1]));
            gradeHttp($both(static fn ($r) => $r->finalizeSection(1, gradeT(14), 1)), 409);          // nothing submitted any more
            gradeHttp($both(static fn ($r) => $r->saveDraft(1, 1, '1.00', null, gradeT(14), 2)), 409);

            // Reads: shape, ordering, unknown ids.
            same([1, 2, 3], array_column($both(static fn ($r) => $r->forSection(1))[1], 'student_id'));
            same([], $both(static fn ($r) => $r->forSection(99))[1]);
            same(null, $both(static fn ($r) => $r->findGrade(99))[1]);
            same(1, $both(static fn ($r) => $r->findGrade(1))[1]['id']);
            same(['finalized'], array_column($both(static fn ($r) => $r->finalizedForStudent(1))[1], 'status'));
            same([], $both(static fn ($r) => $r->finalizedForStudent(99))[1]);

            // Corrections: 404, then status, SoD, stale, unchanged, ok, stale again.
            $seq = $f['sql']->findGrade(1)->seq();
            gradeHttp($both(static fn ($r) => $r->correct(99, '1.50', 'Recount.', 1, gradeT(15), 1)), 404);
            gradeHttp($both(static fn ($r) => $r->correct(1, '1.50', 'Recount.', $seq, gradeT(15), 2)), 403);
            gradeHttp($both(static fn ($r) => $r->correct(1, '1.50', 'Recount.', $seq + 5, gradeT(15), 1)), 409);
            gradeHttp($both(static fn ($r) => $r->correct(1, '2.00', 'Recount.', $seq, gradeT(15), 1)), 409, ['value' => 'The new value must differ from the current value.']);
            // Parity (trusted run 2026-11-26): an out-of-scale correction is the same 422 on both, before any write.
            $before = gradeLastSeq($f['owner']);
            gradeHttp($both(static fn ($r) => $r->correct(1, '3.25', 'Recount.', $seq, gradeT(15), 1)), 422, ['value' => 'Use a value from the grading scale.']);
            same($before, gradeLastSeq($f['owner']));
            $corrected = $both(static fn ($r) => $r->correct(1, '1.50', 'Recount.', $seq, gradeT(16), 1));
            same(['1.50', 'finalized', gradeLastSeq($f['owner'])], [$corrected[1]['value'], $corrected[1]['status'], $corrected[1]['seq']]);   // seq is the global history seq
            $stale = $both(static fn ($r) => $r->correct(1, '3.00', 'Again.', $seq, gradeT(17), 1));
            gradeHttp($stale, 409);
            check(array_key_exists('stale_grade', $stale[3]), 'stale_grade key expected.');

            $mockHistory = (new JsonStore($f['dir'] . '/grades.json'))->read()['history'];
            same($mockHistory, gradeSqlHistory($f['owner']));
            same(count($mockHistory), gradeLastSeq($f['owner']));
            same(range(1, count($mockHistory)), array_column($mockHistory, 'seq'));
        } finally { removeTestDirectory($f['dir']); }
    });

    test('T-32 MySQL: finalize guards (missing and draft roster students, wrong status) match the mock; dropped students keep their grade; no nesting', static function (): void {
        $f = gradeMysql();
        try {
            $both = static fn (callable $op): array => gradeBoth($f, $op);
            gradeHttp($both(static fn ($r) => $r->finalizeSection(1, gradeT(1), 1)), 409, ['grades_missing' => [1, 2, 3]]);
            foreach ([1, 2, 3] as $id) {
                $both(static fn ($r) => $r->saveDraft(1, $id, '1.00', null, gradeT(2), 2));
            }
            same(2, count($both(static fn ($r) => $r->submitSection(1, [1, 2], gradeT(3), 2))[1]));   // student 3 stays draft
            gradeHttp($both(static fn ($r) => $r->finalizeSection(1, gradeT(4), 1)), 409, ['grades_missing' => [3]]);
            gradeHttp($both(static fn ($r) => $r->correct(3, '2.00', 'Reason.', 3, gradeT(4), 1)), 409, ['status' => 'Only finalized grades can be corrected.']);
            same(1, count($both(static fn ($r) => $r->submitSection(1, [1, 2, 3], gradeT(5), 2))[1]));
            // Student 3 drops: the roster re-read under the lock excludes them, their submitted grade is still finalized.
            $f['sqlCatalog']->dropEnrollment($f['enrollments'][3], 3, gradeT(6), 5);
            $f['mockCatalog']->dropEnrollment($f['enrollments'][3], 3, gradeT(6), 5);
            same(3, count($both(static fn ($r) => $r->finalizeSection(1, gradeT(7), 1))[1]));
            same([1, 2], $f['sqlCatalog']->approvedStudentIds(1));
            same(['finalized', 'finalized', 'finalized'], array_column($both(static fn ($r) => $r->forSection(1))[1], 'status'));

            // MySQL only: no nested transactions, and a refused finalize leaves no open transaction or write behind.
            $before = gradeLastSeq($f['owner']);
            $f['app']->beginTransaction();
            try {
                throws(RuntimeException::class, static fn () => $f['sql']->finalizeSection(1, gradeT(8), 1));
                throws(RuntimeException::class, static fn () => $f['sql']->saveDraft(1, 1, '1.00', null, gradeT(8), 2));
            } finally { $f['app']->rollBack(); }
            same($before, gradeLastSeq($f['owner']));
        } finally { removeTestDirectory($f['dir']); }
    });

    test('T-32 MySQL: finalizeSection refuses the actor\'s own student id under the locks (403, nothing written), same as the mock', static function (): void {
        $f = gradeMysql();
        try {
            $both = static fn (callable $op): array => gradeBoth($f, $op);
            foreach ([1, 2, 3] as $id) {
                $both(static fn ($r) => $r->saveDraft(1, $id, '1.00', null, gradeT(2), 2));
            }
            $both(static fn ($r) => $r->submitSection(1, [1, 2, 3], gradeT(3), 2));
            $seq = gradeLastSeq($f['owner']);
            gradeHttp($both(static fn ($r) => $r->finalizeSection(1, gradeT(4), 1, 3)), 403);          // on the locked roster
            $f['sqlCatalog']->dropEnrollment($f['enrollments'][3], 3, gradeT(5), 5);
            $f['mockCatalog']->dropEnrollment($f['enrollments'][3], 3, gradeT(5), 5);
            gradeHttp($both(static fn ($r) => $r->finalizeSection(1, gradeT(6), 1, 3)), 403);          // dropped, but has a grade row
            same($seq, gradeLastSeq($f['owner']));
            same(['submitted', 'submitted', 'submitted'], array_column($both(static fn ($r) => $r->forSection(1))[1], 'status'));
            same(3, count($both(static fn ($r) => $r->finalizeSection(1, gradeT(7), 1))[1]));          // 3-arg call is unaffected
        } finally { removeTestDirectory($f['dir']); }
    });

    test('T-32 MySQL: returnSection refuses the actor\'s own student id under the lock (403, nothing written), same as the mock', static function (): void {
        $f = gradeMysql();
        try {
            $both = static fn (callable $op): array => gradeBoth($f, $op);
            foreach ([1, 2, 3] as $id) {
                $both(static fn ($r) => $r->saveDraft(1, $id, '1.00', null, gradeT(2), 2));
            }
            $both(static fn ($r) => $r->submitSection(1, [1, 2, 3], gradeT(3), 2));
            $seq = gradeLastSeq($f['owner']);
            gradeHttp($both(static fn ($r) => $r->returnSection(1, 'Redo.', gradeT(4), 1, 3)), 403);
            same($seq, gradeLastSeq($f['owner']));
            same(['submitted', 'submitted', 'submitted'], array_column($both(static fn ($r) => $r->forSection(1))[1], 'status'));
            same(3, count($both(static fn ($r) => $r->returnSection(1, 'Redo.', gradeT(5), 1))[1]));   // null id is unaffected
        } finally { removeTestDirectory($f['dir']); }
    });

    test('T-32 MySQL: saveDraft refuses non-roster and dropped students (L2) and records remarks history (L3), same as the mock', static function (): void {
        $f = gradeMysql([1, 2], [3]);
        try {
            $both = static fn (callable $op): array => gradeBoth($f, $op);
            $counts = static fn (): array => [(int) $f['owner']->query('SELECT COUNT(*) FROM grades')->fetchColumn(),
                (int) $f['owner']->query('SELECT COUNT(*) FROM grade_history')->fetchColumn(), gradeLastSeq($f['owner'])];
            // L2: pending (student 3) and unknown (99) students are not on the roster: 404, nothing written.
            foreach ([3, 99] as $id) {
                $refused = $both(static fn ($r) => $r->saveDraft(1, $id, '1.00', null, gradeT(1), 2));
                gradeHttp($refused, 404);
                same('Student not found in this class.', $refused[2]);
            }
            same([0, 0, 0], $counts());
            // L3: entered carries new remarks only; a remarks-only change is one 'updated' row with old and new remarks.
            $both(static fn ($r) => $r->saveDraft(1, 1, '1.00', 'first', gradeT(2), 2));
            $both(static fn ($r) => $r->saveDraft(1, 1, '1.00', 'second', gradeT(3), 2));
            $both(static fn ($r) => $r->saveDraft(1, 1, '1.00', null, gradeT(4), 2));
            $both(static fn ($r) => $r->saveDraft(1, 2, '2.00', null, gradeT(5), 2));
            same([4, 4], [$counts()[1], $counts()[2]]);
            $rows = array_map(static fn (array $h): array => [$h['action'], $h['old_value'], $h['new_value'], $h['old_remarks'], $h['new_remarks']], gradeSqlHistory($f['owner']));
            same([['entered', null, '1.00', null, 'first'], ['updated', '1.00', '1.00', 'first', 'second'],
                ['updated', '1.00', '1.00', 'second', null], ['entered', null, '2.00', null, null]], $rows);
            // Workflow rows and corrections: submitted/returned/finalized carry no remarks, a correction repeats the unchanged current remarks.
            $both(static fn ($r) => $r->saveDraft(1, 1, '1.00', 'kept', gradeT(6), 2));
            $both(static fn ($r) => $r->submitSection(1, [1, 2], gradeT(7), 2));
            $both(static fn ($r) => $r->returnSection(1, 'Check.', gradeT(8), 1));
            $both(static fn ($r) => $r->submitSection(1, [1, 2], gradeT(9), 2));
            $both(static fn ($r) => $r->finalizeSection(1, gradeT(10), 1));
            $both(static fn ($r) => $r->correct(1, '1.50', 'Recount.', $f['sql']->findGrade(1)->seq(), gradeT(11), 1));
            $history = gradeSqlHistory($f['owner']);
            same($history, (new JsonStore($f['dir'] . '/grades.json'))->read()['history']);
            foreach ($history as $h) {
                if (in_array($h['action'], ['submitted', 'returned', 'finalized'], true)) {
                    same([null, null], [$h['old_remarks'], $h['new_remarks']]);
                }
            }
            $corrected = end($history);
            same(['corrected', 'kept', 'kept'], [$corrected['action'], $corrected['old_remarks'], $corrected['new_remarks']]);
            same([], gradeVerify($f['owner']));
            // A student who drops after drafting can no longer be edited (404, nothing written).
            $f['sqlCatalog']->dropEnrollment($f['enrollments'][2], 2, gradeT(12), 4);
            $f['mockCatalog']->dropEnrollment($f['enrollments'][2], 2, gradeT(12), 4);
            $before = $counts();
            gradeHttp($both(static fn ($r) => $r->saveDraft(1, 2, '3.00', null, gradeT(13), 2)), 404);
            same($before, $counts());
        } finally { removeTestDirectory($f['dir']); }
    });

    test('T-32 MySQL: DB constraints back up the model (grades and grade_history CHECK, UNIQUE, FKs)', static function (): void {
        $f = gradeMysql([]);
        try {
            $owner = $f['owner'];
            $grade = static fn (array $v) => $owner->prepare('INSERT INTO grades (section_id, student_id, value, remarks, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_replace([1, 1, '1.00', null, 'draft', '2025-01-01 00:00:00', '2025-01-01 00:00:00'], $v));
            $grade([]);
            foreach (['duplicate pair' => [], 'bad status' => [1 => 2, 4 => 'done'], 'empty value' => [1 => 2, 2 => ''],
                'long value' => [1 => 2, 2 => '123456789'], 'empty remarks' => [1 => 2, 3 => ''],
                'updated before created' => [1 => 2, 6 => '2024-12-31 00:00:00'], 'unknown student' => [1 => 99], 'unknown section' => [0 => 999]] as $values) {
                throws(PDOException::class, static fn () => $grade($values));
            }
            $grade([1 => 2, 3 => 'ok', 4 => 'finalized']);
            $history = static fn (array $v) => $owner->prepare('INSERT INTO grade_history (seq, at, action, grade_id, section_id, student_id, old_value, new_value, reason, actor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_replace([1, '2025-01-01 00:00:00', 'entered', 1, 1, 1, null, '1.00', null, 1], $v));
            $history([]);
            foreach (['duplicate seq' => [], 'seq 0' => [0 => 0], 'bad action' => [0 => 2, 2 => 'done'],
                'entered with old' => [0 => 2, 6 => '1.00'], 'entered without new' => [0 => 2, 7 => null], 'entered with reason' => [0 => 2, 8 => 'Why.'],
                'updated without old' => [0 => 2, 2 => 'updated'], 'corrected without reason' => [0 => 2, 2 => 'corrected', 6 => '1.00', 7 => '2.00'],
                'returned without reason' => [0 => 2, 2 => 'returned', 7 => null], 'submitted with values' => [0 => 2, 2 => 'submitted'],
                'submitted with reason' => [0 => 2, 2 => 'submitted', 7 => null, 8 => 'Why.'], 'empty reason' => [0 => 2, 2 => 'returned', 7 => null, 8 => ''],
                'long old value' => [0 => 2, 2 => 'updated', 6 => '123456789', 7 => '2.00'], 'unknown grade' => [0 => 2, 3 => 999],
                'unknown section' => [0 => 2, 4 => 999], 'unknown student' => [0 => 2, 5 => 99], 'unknown actor' => [0 => 2, 9 => 99]] as $values) {
                throws(PDOException::class, static fn () => $history($values));
            }
            $history([0 => 2, 2 => 'submitted', 7 => null]);
            $history([0 => 3, 2 => 'returned', 7 => null, 8 => 'Because.']);
            $history([0 => 4, 2 => 'updated', 6 => '1.00', 7 => '2.00']);
            $history([0 => 5, 2 => 'corrected', 6 => '2.00', 7 => '3.00', 8 => 'Fix.']);
            $history([0 => 6, 2 => 'finalized', 7 => null]);
            // V0009 remarks columns: non-empty when set; none on submitted/returned/finalized; pre-V0009-shaped valued rows (both NULL) stay valid.
            $remarks = static fn (array $v) => $owner->prepare('INSERT INTO grade_history (seq, at, action, grade_id, section_id, student_id, old_value, new_value, old_remarks, new_remarks, reason, actor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_replace([7, '2025-01-01 00:00:00', 'updated', 1, 1, 1, '1.00', '2.00', null, null, null, 1], $v));
            foreach (['empty old remarks' => [8 => ''], 'empty new remarks' => [9 => ''],
                'submitted with old remarks' => [2 => 'submitted', 6 => null, 7 => null, 8 => 'x'],
                'finalized with new remarks' => [2 => 'finalized', 6 => null, 7 => null, 9 => 'x'],
                'returned with remarks' => [2 => 'returned', 6 => null, 7 => null, 8 => 'x', 9 => 'y', 10 => 'Because.']] as $values) {
                throws(PDOException::class, static fn () => $remarks($values));
            }
            $remarks([]);
            $remarks([0 => 8, 8 => 'before', 9 => 'after']);
            // The grades lock row is seeded by V0008 and nothing else was added.
            same(['auth', 'enrollment', 'faculty', 'grades', 'students'], $owner->query('SELECT name FROM repository_locks ORDER BY name')->fetchAll(PDO::FETCH_COLUMN));
        } finally { removeTestDirectory($f['dir']); }
    });

    test('T-32 MySQL: the app account cannot delete grades or rewrite and delete history', static function (): void {
        $f = gradeMysql([1]);
        try {
            $f['sql']->saveDraft(1, 1, '1.00', null, gradeT(1), 2);
            $app = MySQLTestSupport::connectApp();
            throws(PDOException::class, static fn () => $app->exec('DELETE FROM grades'));
            throws(PDOException::class, static fn () => $app->exec('DELETE FROM grade_history'));
            throws(PDOException::class, static fn () => $app->exec("UPDATE grade_history SET action = 'updated'"));
            throws(PDOException::class, static fn () => $app->exec('TRUNCATE TABLE grade_history'));
            same([1, 1], [(int) $f['owner']->query('SELECT COUNT(*) FROM grades')->fetchColumn(), (int) $f['owner']->query('SELECT COUNT(*) FROM grade_history')->fetchColumn()]);
        } finally { removeTestDirectory($f['dir']); }
    });

    test('T-32 MySQL: failed writes leave no partial state; corrupt rows and a missing lock fail closed', static function (): void {
        $f = gradeMysql([1]);
        try {
            $owner = $f['owner'];
            $sql = $f['sql'];
            // The student FK is only a backstop (the roster check comes first, so a roster row for unknown student 99 is forced in with FK checks off):
            // generic error, no row, no history, no seq burned.
            try {
                $owner->exec('SET SESSION foreign_key_checks = 0');
                $owner->exec("INSERT INTO enrollments (student_id, section_id, status, requested_at, decided_at, decided_by) VALUES (99, 1, 'approved', '2025-01-01 03:04:05', '2025-01-01 03:04:05', 1)");
            } finally { $owner->exec('SET SESSION foreign_key_checks = 1'); }
            same('Grade repository unavailable.', throws(RuntimeException::class, fn () => $sql->saveDraft(1, 99, '1.00', null, gradeT(1), 2))->getMessage());
            same([0, 0, 0, false], [(int) $owner->query('SELECT COUNT(*) FROM grades')->fetchColumn(),
                (int) $owner->query('SELECT COUNT(*) FROM grade_history')->fetchColumn(), gradeLastSeq($owner), $f['app']->inTransaction()]);
            // Missing lock row.
            $owner->exec("DELETE FROM repository_locks WHERE name = 'grades'");
            try {
                same('Grade repository lock missing.', throws(RuntimeException::class, fn () => $sql->saveDraft(1, 1, '1.00', null, gradeT(1), 2))->getMessage());
            } finally { $owner->exec("INSERT INTO repository_locks (name, last_seq) VALUES ('grades', 0)"); }
            same(0, (int) $owner->query('SELECT COUNT(*) FROM grades')->fetchColumn());
            // A failure after the first statements rolls everything back: an empty reason violates ck_gh_reason_text after the UPDATE.
            // (the failed INSERT above burned an AUTO_INCREMENT id, so the id is read back)
            $gid = $sql->saveDraft(1, 1, '1.00', null, gradeT(2), 2)['grade']->id();
            $sql->submitSection(1, [1], gradeT(3), 2);
            same('Grade repository unavailable.', throws(RuntimeException::class, fn () => $sql->returnSection(1, '', gradeT(4), 1))->getMessage());
            same(['submitted', 2, 2], [$owner->query('SELECT status FROM grades WHERE id = ' . $gid)->fetchColumn(),
                (int) $owner->query('SELECT COUNT(*) FROM grade_history')->fetchColumn(), gradeLastSeq($owner)]);
            // An HttpException writes nothing either.
            gradeHttp(gradeBoth(['mock' => $sql, 'sql' => $sql, 'app' => $f['app']], static fn ($r) => $r->returnSection(99, 'None.', gradeT(5), 1)), 409);
            same([2, 2], [(int) $owner->query('SELECT COUNT(*) FROM grade_history')->fetchColumn(), gradeLastSeq($owner)]);
            // Corrupt stored timestamp: generic, unchained error on read.
            $owner->exec('SET SESSION sql_mode = ""');
            $owner->exec("UPDATE grades SET created_at = '0000-00-00 00:00:00' WHERE id = " . $gid);
            $error = throws(RuntimeException::class, fn () => $sql->findGrade($gid));
            same(['Invalid stored grade.', null], [$error->getMessage(), $error->getPrevious()]);
            throws(RuntimeException::class, fn () => $sql->forSection(1));
        } finally { removeTestDirectory($f['dir']); }
    });

    test('T-32 MySQL: verifier covers 35 checks, stays clean, and flags each seeded grade defect', static function (): void {
        $f = gradeMysql([1, 2], [3]);
        try {
            $owner = $f['owner'];
            $sql = $f['sql'];
            same(35, count((new IntegrityVerifier($owner))->verify()));
            same([], gradeVerify($owner));
            $sql->saveDraft(1, 1, '1.00', null, gradeT(1), 2);
            $sql->saveDraft(1, 2, '2.00', null, gradeT(2), 2);
            $sql->submitSection(1, [1, 2], gradeT(3), 2);
            $sql->returnSection(1, 'Recheck.', gradeT(4), 1);
            $sql->submitSection(1, [1, 2], gradeT(5), 2);
            $sql->finalizeSection(1, gradeT(6), 1);
            $sql->correct(1, '1.25', 'Recount.', $sql->findGrade(1)->seq(), gradeT(7), 1);
            same([], gradeVerify($owner));

            // finalized_section_missing_grade: a late approval after finalization is reported (section id), then cleared by a draft.
            $f['sqlCatalog']->decideEnrollment($f['enrollments'][3], 'approved', null, gradeT(8), 1);
            same(['finalized_section_missing_grade' => [1]], gradeVerify($owner));
            $sql->saveDraft(1, 3, '2.00', null, gradeT(9), 2);
            same([], gradeVerify($owner));

            // grade_model: an impossible stored timestamp.
            $created = $owner->query('SELECT created_at FROM grades WHERE id = 1')->fetchColumn();
            $owner->exec('SET SESSION sql_mode = ""');
            $owner->exec("UPDATE grades SET created_at = '0000-00-00 00:00:00' WHERE id = 1");
            same(['grade_model' => [1]], gradeVerify($owner));
            $owner->prepare('UPDATE grades SET created_at = ? WHERE id = 1')->execute([$created]);
            same([], gradeVerify($owner));

            // grade_history_seq (and the extended repository_last_seq max map): a gap at the end.
            $last = gradeLastSeq($owner);
            $owner->exec('UPDATE grade_history SET seq = ' . ($last + 10) . ' WHERE seq = ' . $last);
            $found = gradeVerify($owner);
            same([$last + 10], $found['grade_history_seq'] ?? []);
            same([-1], $found['repository_last_seq'] ?? []);
            $owner->exec('UPDATE grade_history SET seq = ' . $last . ' WHERE seq = ' . ($last + 10));
            same([], gradeVerify($owner));

            // grade_history_replay: the current value no longer matches the history.
            $owner->exec("UPDATE grades SET value = '3.00' WHERE id = 2");   // in the V0010 scale, so ck_grades_value allows the tamper
            same(['grade_history_replay' => [2]], gradeVerify($owner));
            $owner->exec("UPDATE grades SET value = '2.00' WHERE id = 2");
            // ... and an action that is illegal in the state machine (finalized -> submitted).
            $owner->exec("UPDATE grade_history SET action = 'submitted', old_value = NULL, new_value = NULL, reason = NULL WHERE grade_id = 1 AND action = 'corrected'");
            same([1], gradeVerify($owner)['grade_history_replay'] ?? []);
            $owner->exec("UPDATE grade_history SET action = 'corrected', old_value = '1.00', new_value = '1.25', reason = 'Recount.' WHERE grade_id = 1 AND action = 'submitted' AND seq = (SELECT s FROM (SELECT MAX(seq) AS s FROM grade_history WHERE grade_id = 1) t)");
            same([], gradeVerify($owner));

            // grade_history_replay, remarks (V0009): recorded remarks must replay to the current remarks.
            $sql->saveDraft(1, 3, '2.00', 'R1', gradeT(10), 2);
            same([], gradeVerify($owner));
            $owner->exec("UPDATE grades SET remarks = 'R2' WHERE id = 3");
            same(['grade_history_replay' => [3]], gradeVerify($owner));
            $owner->exec("UPDATE grades SET remarks = 'R1' WHERE id = 3");
            $owner->exec("UPDATE grade_history SET new_remarks = 'R9' WHERE grade_id = 3 AND action = 'updated'");
            same(['grade_history_replay' => [3]], gradeVerify($owner));
            $owner->exec("UPDATE grade_history SET new_remarks = 'R1' WHERE grade_id = 3 AND action = 'updated'");
            same([], gradeVerify($owner));

            // V0009 cutover: grade 2's history (2025 timestamps) predates it, so NULL history remarks are tolerated and a changed remark is not seen ...
            $owner->exec("UPDATE grades SET remarks = 'X' WHERE id = 2");
            same([], gradeVerify($owner));
            // ... but once its rows are at/after V0009's finished_at they are strict: NULL remarks mean NULL, so the tampered remark is flagged.
            $owner->exec("UPDATE grade_history SET at = '2999-01-01 00:00:00' WHERE grade_id = 2");
            same(['grade_history_replay' => [2]], gradeVerify($owner));
            $owner->exec('UPDATE grades SET remarks = NULL WHERE id = 2');
            same([], gradeVerify($owner));

            // grade_enrollment: a non-draft grade whose student never had an approved enrollment row in the section.
            $row = $owner->query('SELECT enrollment_id, seq, at, action, actor_id FROM enrollment_history WHERE enrollment_id = ' . $f['enrollments'][1] . " AND action = 'approved'")->fetch(PDO::FETCH_ASSOC);
            $owner->exec('DELETE FROM enrollment_history WHERE enrollment_id = ' . $f['enrollments'][1] . " AND action = 'approved'");
            same([1], gradeVerify($owner)['grade_enrollment'] ?? []);
            $owner->prepare('INSERT INTO enrollment_history (enrollment_id, seq, at, action, actor_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([$row['enrollment_id'], $row['seq'], $row['at'], $row['action'], $row['actor_id']]);
            same([], gradeVerify($owner));
        } finally { removeTestDirectory($f['dir']); }
    });

    test('T-32 MySQL concurrency: approve vs finalize, repeated, never leaves a finalized section with an unreported approved student', static function (): void {
        for ($round = 0; $round < 5; $round++) {
            $f = gradeMysql([1, 2], [3]);
            try {
                $sql = $f['sql'];
                $sql->saveDraft(1, 1, '1.00', null, gradeT(1), 2);
                $sql->saveDraft(1, 2, '2.00', null, gradeT(2), 2);
                $sql->submitSection(1, [1, 2], gradeT(3), 2);
                [$finalize, $approve] = gradeMysqlRace([['finalize', ['section_id' => 1, 'actor_id' => 1]],
                    ['approve', ['enrollment_id' => $f['enrollments'][3], 'actor_id' => 1]]]);
                same(true, $approve['ok']);
                $statuses = $f['owner']->query('SELECT status FROM grades WHERE section_id = 1 ORDER BY student_id')->fetchAll(PDO::FETCH_COLUMN);
                if ($finalize['ok']) {
                    same(['finalized', 'finalized'], $statuses);
                    same(['finalized_section_missing_grade' => [1]], gradeVerify($f['owner']));   // reported, not lost
                } else {
                    same([409, [3]], [$finalize['status'], $finalize['errors']['grades_missing'] ?? null]);
                    same(['submitted', 'submitted'], $statuses);
                    same([], gradeVerify($f['owner']));
                }
                same([1, 2, 3], $f['sqlCatalog']->approvedStudentIds(1));
                $seqs = array_map('intval', $f['owner']->query('SELECT seq FROM grade_history ORDER BY seq')->fetchAll(PDO::FETCH_COLUMN));
                same(range(1, count($seqs)), $seqs);
                same(count($seqs), gradeLastSeq($f['owner']));
            } finally { removeTestDirectory($f['dir']); }
        }
    });

    test('T-32 MySQL concurrency: two corrections with the same expected_seq, exactly one wins and the other is stale', static function (): void {
        for ($round = 0; $round < 3; $round++) {
            $f = gradeMysql([1]);
            try {
                $sql = $f['sql'];
                $sql->saveDraft(1, 1, '1.00', null, gradeT(1), 2);
                $sql->submitSection(1, [1], gradeT(2), 2);
                $sql->finalizeSection(1, gradeT(3), 1);
                $seq = $sql->findGrade(1)->seq();
                $results = gradeMysqlRace([
                    ['correct', ['grade_id' => 1, 'value' => '2.00', 'reason' => 'First.', 'expected_seq' => $seq, 'actor_id' => 1]],
                    ['correct', ['grade_id' => 1, 'value' => '3.00', 'reason' => 'Second.', 'expected_seq' => $seq, 'actor_id' => 6]],
                ]);
                same(1, count(array_filter($results, static fn (array $r): bool => $r['ok'])));
                $loser = array_values(array_filter($results, static fn (array $r): bool => !$r['ok']))[0];
                same(409, $loser['status']);
                check(array_key_exists('stale_grade', (array) $loser['errors']), 'The loser must get stale_grade.');
                $winnerValue = $results[0]['ok'] ? '2.00' : '3.00';
                same([$winnerValue, 1], [$f['owner']->query('SELECT value FROM grades WHERE id = 1')->fetchColumn(),
                    (int) $f['owner']->query("SELECT COUNT(*) FROM grade_history WHERE action = 'corrected'")->fetchColumn()]);
                same([], gradeVerify($f['owner']));
            } finally { removeTestDirectory($f['dir']); }
        }
    });

    test('T-32 MySQL: return-to-draft refuses an entrant under the grades lock (403, nothing written), same as the mock', static function (): void {
        $f = gradeMysql();
        try {
            $both = static fn (callable $op): array => gradeBoth($f, $op);
            foreach ([1, 2, 3] as $id) {
                $both(static fn ($r) => $r->saveDraft(1, $id, '1.00', null, gradeT(2), 2));        // actor 2 entered
            }
            $both(static fn ($r) => $r->submitSection(1, [1, 2, 3], gradeT(3), 4));                  // actor 4 submitted
            $seq = gradeLastSeq($f['owner']);
            gradeHttp($both(static fn ($r) => $r->returnSection(1, 'Redo.', gradeT(4), 2)), 403);   // entrant
            gradeHttp($both(static fn ($r) => $r->returnSection(1, 'Redo.', gradeT(4), 4)), 403);   // submitter
            same($seq, gradeLastSeq($f['owner']));
            same(['submitted', 'submitted', 'submitted'], array_column($both(static fn ($r) => $r->forSection(1))[1], 'status'));
            same(3, count($both(static fn ($r) => $r->returnSection(1, 'Redo.', gradeT(5), 1))[1]));   // unrelated actor
            $returned = array_values(array_filter(gradeSqlHistory($f['owner']), static fn (array $h): bool => $h['action'] === 'returned'));
            same([3, 'Redo.'], [count($returned), $returned[0]['reason']]);
        } finally { removeTestDirectory($f['dir']); }
    });

    // V0010 (owner decision 2026-11-26): ck_grades_value allows exactly the demo-approved scale GradeScale::demo(); the official policy stays pending (empty).
    test('T-32 MySQL: ck_grades_value is consistent with GradeScale::demo() and official() stays empty', static function (): void {
        $f = gradeMysql();
        try {
            $rows = $f['owner']->query("SELECT c.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c "
                . "ON c.CONSTRAINT_SCHEMA = t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME = t.CONSTRAINT_NAME "
                . "WHERE t.CONSTRAINT_SCHEMA = DATABASE() AND t.TABLE_NAME = 'grades' AND t.CONSTRAINT_NAME = 'ck_grades_value'")->fetchAll(PDO::FETCH_COLUMN);
            same(1, count($rows));
            preg_match_all("/'((?:[^'\\\\]|\\\\.|'')*)'/", $rows[0], $m);
            $allowed = array_map(static fn (string $v): string => str_replace("''", "'", $v), $m[1]);
            $demo = App\Faculty\Services\GradeScale::demo();
            $expected = (new ReflectionProperty($demo, 'values'))->getValue($demo);
            sort($allowed);
            sort($expected);
            same($expected, $allowed);
            same(12, count($expected));
            same(false, App\Faculty\Services\GradeScale::official()->isConfirmed());   // official university policy still pending
        } finally { removeTestDirectory($f['dir']); }
    });

    test('T-32 MySQL: V0010 scale CHECKs refuse values outside the demo scale and accept each code', static function (): void {
        $f = gradeMysql([]);
        try {
            $owner = $f['owner'];
            $ins = static fn (int $student, string $value) => $owner->prepare("INSERT INTO grades (section_id, student_id, value, remarks, status, created_at, updated_at) VALUES (1, ?, ?, NULL, 'draft', '2025-01-01 00:00:00', '2025-01-01 00:00:00')")
                ->execute([$student, $value]);
            foreach (['3.25', 'inc', '5', '4.00', '1.0', '1.00 ', ' INC'] as $bad) {
                throws(PDOException::class, static fn () => $ins(2, $bad));
            }
            $ins(1, '1.00');
            foreach (['1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00', '5.00', 'INC', 'DRP', '1.00'] as $code) {
                $owner->exec("UPDATE grades SET value = '" . $code . "' WHERE student_id = 1");   // each allowed code is accepted
            }
            $history = static fn (?string $old, ?string $new) => $owner->prepare('INSERT INTO grade_history (seq, at, action, grade_id, section_id, student_id, old_value, new_value, reason, actor_id) VALUES (?, ?, ?, 1, 1, 1, ?, ?, ?, 1)')
                ->execute([1, '2025-01-01 00:00:00', $old === null ? 'entered' : 'corrected', $old, $new, $old === null ? null : 'Fix.']);
            throws(PDOException::class, static fn () => $history(null, '3.25'));
            throws(PDOException::class, static fn () => $history('3.25', '1.00'));
            throws(PDOException::class, static fn () => $history('1.00', 'inc'));
            throws(PDOException::class, static fn () => $history('1.00', '1.00 '));   // NO PAD: trailing space is not the code
            $history(null, 'INC');
        } finally { removeTestDirectory($f['dir']); }
    });
}
