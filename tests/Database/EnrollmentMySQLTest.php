<?php
declare(strict_types=1);

// T-22 MySQL tests (written, NOT yet run). Online only: they need AIS_MYSQL_TESTS=1, the ais_test target,
// AIS_MYSQL_TEST_APP_PASSWORD and app_grants.sql re-applied after V0007 (table grants for the two new tables).
// The importer round trip reuses the phase8* helpers of ImportMySQLTest.php/ImportOfflineTest.php at run time only.

use App\Core\HttpException;
use App\Core\JsonStore;
use App\Enrollment\Repositories\MockCatalogRepository;
use App\Enrollment\Repositories\MySQLCatalogRepository;
use Database\Tools\IntegrityVerifier;
use Database\Tools\MigrationRunner;

require_once dirname(__DIR__) . '/Enrollment/CatalogRepositoryContract.php';
require_once __DIR__ . '/MySQLTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/tools/MigrationRunner.php';
require_once dirname(__DIR__, 2) . '/database/tools/IntegrityVerifier.php';

/** Fresh schema (V0001-V0007), 6 users, one program, students 1-3 (users 3-5); repo runs as the app account. */
function t22Mysql(): array
{
    $owner = MySQLTestSupport::connect();
    MySQLTestSupport::reset($owner);
    (new MigrationRunner($owner, 'ais_test', dirname(__DIR__, 2) . '/database/migrations'))->up();
    $user = $owner->prepare('INSERT INTO users (id, email, status, password_hash, auth_version) VALUES (?, ?, ?, ?, ?)');
    $hash = password_hash('T22 contract passphrase', PASSWORD_DEFAULT);
    for ($id = 1; $id <= 6; $id++) {
        $user->execute([$id, "t22user{$id}@example.test", 'active', $hash, 1]);
    }
    $owner->exec("INSERT INTO programs (code, name, max_year_level) VALUES ('BSCS', 'Computer Science', 4)");
    $student = $owner->prepare('INSERT INTO students (id, user_id, student_number, first_name, middle_name, last_name, program_code, '
        . 'year_level, academic_status, admission_year, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)');
    foreach ([1, 2, 3] as $id) {
        $student->execute([$id, $id + 2, "T22-{$id}", 'Student', "Number{$id}", 'BSCS', 1, 'active', 2025, '2025-01-01 03:04:05', '2025-01-01 03:04:05']);
    }
    MySQLTestSupport::completeFixture($owner, [1 => 'registrar', 2 => 'faculty', 3 => 'student', 4 => 'student', 5 => 'student', 6 => 'admin']);
    return [new MySQLCatalogRepository(MySQLTestSupport::connectApp()), $owner];
}

/** @return array{0:\App\Enrollment\Models\Term,1:list<\App\Enrollment\Models\Section>} open term, then CS101 (cap 1, MON 09-10), CS102 (MON 09:30-10:30), CS103 (TUE 09-10) */
function t22Catalog(MySQLCatalogRepository $repo): array
{
    $term = $repo->createTerm(catalogTermFields('2025-A', ['enrollment_open' => true]), catalogTime());
    return [$term, [
        $repo->createSection(catalogSectionFields($term->id(), 'CS101', 'A', ['capacity' => 1]), catalogTime()),
        $repo->createSection(catalogSectionFields($term->id(), 'CS102', 'A', ['meetings' => [catalogMeeting('MON', '09:30', '10:30')]]), catalogTime()),
        $repo->createSection(catalogSectionFields($term->id(), 'CS103', 'A', ['meetings' => [catalogMeeting('TUE', '09:00', '10:00')]]), catalogTime()),
    ]];
}

function t22Refused(callable $action, int $status, array $errors): void
{
    $error = throws(HttpException::class, $action);
    same([$status, $errors], [$error->status, $error->errors]);
}

function t22Online(): bool
{
    return getenv('AIS_MYSQL_TESTS') === '1' && is_string(getenv('AIS_MYSQL_TEST_APP_PASSWORD')) && MySQLTestSupport::enabled();
}

if (t22Online()) {
    test('T-22 MySQL: request, approve, reject and drop follow the mock rules, order, clamping and history', static function (): void {
        [$repo, $owner] = t22Mysql();
        [$term, [$s1, $s2, $s3]] = t22Catalog($repo);
        $closed = $repo->createTerm(catalogTermFields('2025-B'), catalogTime());
        $sClosed = $repo->createSection(catalogSectionFields($closed->id(), 'CS201', 'A'), catalogTime());
        $count = static fn (int $id): int => (int) $owner->query('SELECT approved_count FROM sections WHERE id = ' . $id)->fetchColumn();
        $history = static fn (int $id): array => $owner->query('SELECT seq, action FROM enrollment_history WHERE enrollment_id = ' . $id . ' ORDER BY seq')->fetchAll(PDO::FETCH_NUM);

        t22Refused(fn () => $repo->requestEnrollment(1, 999, catalogTime(), 3), 422, ['section_id' => 'Unknown section.']);
        t22Refused(fn () => $repo->requestEnrollment(1, $sClosed->id(), catalogTime(), 3), 409, ['section_id' => 'Enrollment is closed for this term.']);
        $first = $repo->requestEnrollment(1, $s1->id(), catalogTime(), 3);
        same(['pending', $term->id(), null], [$first->status(), $first->termId(), $first->toArray(true)['decided_by']]);
        t22Refused(fn () => $repo->requestEnrollment(1, $s1->id(), catalogTime(), 3), 409, ['section_id' => 'You already have a pending or approved request for this section.']);
        t22Refused(fn () => $repo->requestEnrollment(1, $s2->id(), catalogTime(), 3), 409,
            ['section_id' => 'This section overlaps CS101-A (section ' . $s1->id() . ') in your schedule.']);

        // Clamping: a clock earlier than requested_at never violates the DB ordering CHECK.
        $approved = $repo->decideEnrollment($first->id(), 'approved', null, '2024-12-31T00:00:00Z', 1);
        same([catalogTime(), 'approved', 1], [$approved->toArray(true)['decided_at'], $approved->status(), $approved->toArray(true)['decided_by']]);
        same(1, $count($s1->id()));
        same(catalogTime(), str_replace(' ', 'T', (string) $owner->query('SELECT at FROM enrollment_history WHERE enrollment_id = ' . $first->id() . ' AND seq = 2')->fetchColumn()) . 'Z');
        t22Refused(fn () => $repo->requestEnrollment(2, $s1->id(), catalogTime(), 4), 409, ['section_id' => 'This section is full.']);
        t22Refused(fn () => $repo->decideEnrollment($first->id(), 'approved', null, catalogTime(), 1), 409, ['status' => 'Only a pending enrollment can be decided.']);
        same(404, throws(HttpException::class, fn () => $repo->decideEnrollment(999, 'approved', null, catalogTime(), 1))->status);
        throws(InvalidArgumentException::class, fn () => $repo->decideEnrollment($first->id(), 'dropped', null, catalogTime(), 1));

        // Not the owner: 404, never 403. The owner drops an approved row: count - 1, decision fields kept.
        same(404, throws(HttpException::class, fn () => $repo->dropEnrollment($first->id(), 2, catalogTime(), 4))->status);
        $dropped = $repo->dropEnrollment($first->id(), 1, '2025-01-02T00:00:00Z', 3)->toArray(true);
        same(['dropped', '2025-01-02T00:00:00Z', catalogTime(), 1], [$dropped['status'], $dropped['dropped_at'], $dropped['decided_at'], $dropped['decided_by']]);
        same(0, $count($s1->id()));
        same([[1, 'requested'], [2, 'approved'], [3, 'dropped']], array_map(static fn (array $r): array => [(int) $r[0], $r[1]], $history($first->id())));
        t22Refused(fn () => $repo->dropEnrollment($first->id(), 1, catalogTime(), 3), 409, ['status' => 'Only a pending or approved enrollment can be dropped.']);

        // Rejection needs a reason; it does not touch approved_count.
        $second = $repo->requestEnrollment(2, $s1->id(), catalogTime(), 4);
        throws(InvalidArgumentException::class, fn () => $repo->decideEnrollment($second->id(), 'rejected', null, catalogTime(), 1));
        $rejected = $repo->decideEnrollment($second->id(), 'rejected', 'Prerequisite missing.', catalogTime(), 1)->toArray(true);
        same(['rejected', 'Prerequisite missing.', 0], [$rejected['status'], $rejected['reason'], $count($s1->id())]);
        same('Prerequisite missing.', $owner->query('SELECT reason FROM enrollment_history WHERE enrollment_id = ' . $second->id() . ' AND seq = 2')->fetchColumn());

        // OQ-2 and N-1: after the term closes a pending request can still be decided, but not dropped or newly requested.
        $late = $repo->requestEnrollment(1, $s3->id(), catalogTime(), 3);
        $repo->updateTerm($term->id(), ['enrollment_open' => false], catalogTime());
        t22Refused(fn () => $repo->dropEnrollment($late->id(), 1, catalogTime(), 3), 409, ['status' => 'Enrollment is closed for this term.']);
        t22Refused(fn () => $repo->requestEnrollment(2, $s3->id(), catalogTime(), 4), 409, ['section_id' => 'Enrollment is closed for this term.']);
        same('approved', $repo->decideEnrollment($late->id(), 'approved', null, catalogTime(), 1)->status());
        same(1, $count($s3->id()));
    });

    test('T-22 MySQL: clash rules, reads, filters and the OQ-3 schedule-change guard', static function (): void {
        [$repo, $owner] = t22Mysql();
        [$term, [$s1, $s2, $s3]] = t22Catalog($repo);
        $repo->updateSection($s1->id(), ['capacity' => 5], catalogTime());
        $a = $repo->requestEnrollment(1, $s1->id(), catalogTime(), 3);
        $b = $repo->requestEnrollment(2, $s1->id(), catalogTime(), 4);
        $c = $repo->requestEnrollment(2, $s3->id(), catalogTime(), 4);
        // Pending rows block a new request (pending + approved) but not an approval (approved only).
        t22Refused(fn () => $repo->requestEnrollment(1, $s2->id(), catalogTime(), 3), 409,
            ['section_id' => 'This section overlaps CS101-A (section ' . $s1->id() . ') in your schedule.']);
        $repo->decideEnrollment($a->id(), 'approved', null, catalogTime(), 1);
        $repo->decideEnrollment($b->id(), 'approved', null, catalogTime(), 1);
        $repo->decideEnrollment($c->id(), 'approved', null, catalogTime(), 1);
        same([1, 2], $repo->approvedStudentIds($s1->id()));
        same([], $repo->approvedStudentIds(999));
        same([$a->id(), $b->id(), $c->id()], array_map(static fn ($e): int => $e->id(), $repo->searchEnrollments([], 1, 10)['enrollments']));
        same([2, [$b->id(), $c->id()]], (static function () use ($repo): array {
            $r = $repo->searchEnrollments(['student_id' => 2, 'status' => 'approved'], 1, 10);
            return [$r['total'], array_map(static fn ($e): int => $e->id(), $r['enrollments'])];
        })());
        same([3, [$b->id()]], (static function () use ($repo): array {
            $r = $repo->searchEnrollments([], 2, 1);
            return [$r['total'], array_map(static fn ($e): int => $e->id(), $r['enrollments'])];
        })());
        same(2, $repo->searchEnrollments(['section_id' => $s1->id()], 1, 10)['total']);
        same(3, $repo->searchEnrollments(['term_id' => $term->id()], 1, 10)['total']);
        same(0, $repo->searchEnrollments(['term_id' => 999], 1, 10)['total']);
        same($b->id(), $repo->findEnrollment($b->id())->id());
        same(null, $repo->findEnrollment(999));

        // OQ-3: moving CS101 onto TUE 09:30 would clash with student 2's approved CS103. Nothing may be written.
        $before = $repo->findSection($s1->id())->toArray();
        $error = throws(HttpException::class, fn () => $repo->updateSection($s1->id(), ['meetings' => [catalogMeeting('TUE', '09:30', '10:30')]], catalogTime()));
        same([409, ['meetings' => 'Student 2 has an approved enrollment in CS103-A (section ' . $s3->id() . ') at an overlapping time.']],
            [$error->status, $error->errors]);
        same($before, $repo->findSection($s1->id())->toArray());
        // A non-overlapping change and a change without meetings are accepted.
        $repo->updateSection($s1->id(), ['meetings' => [catalogMeeting('WED', '09:00', '10:00')]], catalogTime());
        same('WED', $repo->findSection($s1->id())->meetings()[0]['day']);
        $repo->updateSection($s1->id(), ['course_title' => 'Renamed'], catalogTime());
        // Once dropped the student no longer blocks the change.
        $repo->dropEnrollment($c->id(), 2, catalogTime(), 4);
        $repo->updateSection($s1->id(), ['meetings' => [catalogMeeting('TUE', '09:30', '10:30')]], catalogTime());
        same('TUE', $repo->findSection($s1->id())->meetings()[0]['day']);
    });

    test('T-22 MySQL: DB constraints back up the model (active pair, decision shape, reasons, ordering, FKs)', static function (): void {
        [$repo, $owner] = t22Mysql();
        [, [$s1]] = t22Catalog($repo);
        $sid = $s1->id();
        $insert = static fn (array $v) => $owner->prepare('INSERT INTO enrollments (student_id, section_id, status, requested_at, decided_at, decided_by, dropped_at, reason) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_replace([1, $sid, 'pending', '2025-01-01 00:00:00', null, null, null, null], $v));
        $insert([]);
        throws(PDOException::class, static fn () => $insert([]));                                          // one active pair
        throws(PDOException::class, static fn () => $insert([2 => 'approved', 4 => '2025-01-02 00:00:00', 5 => 1])); // still the same pair
        $owner->exec("UPDATE enrollments SET status = 'dropped', dropped_at = '2025-01-02 00:00:00'");
        $insert([]);                                                                                       // dropped rows free the pair
        foreach ([
            'bad status' => [0 => 2, 2 => 'done'],
            'pending with decision' => [0 => 2, 4 => '2025-01-02 00:00:00', 5 => 1],
            'approved without decider' => [0 => 2, 2 => 'approved', 4 => '2025-01-02 00:00:00'],
            'rejected without reason' => [0 => 2, 2 => 'rejected', 4 => '2025-01-02 00:00:00', 5 => 1],
            'decided before requested' => [0 => 2, 2 => 'approved', 4 => '2024-12-31 00:00:00', 5 => 1],
            'dropped before requested' => [0 => 2, 2 => 'dropped', 6 => '2024-12-31 00:00:00'],
            'dropped without dropped_at' => [0 => 2, 2 => 'dropped'],
            'half decision on dropped' => [0 => 2, 2 => 'dropped', 4 => '2025-01-02 00:00:00', 6 => '2025-01-03 00:00:00'],
            'empty reason' => [0 => 2, 7 => ''],
            'unknown student' => [0 => 99],
            'unknown section' => [1 => 999],
            'unknown decider' => [0 => 2, 2 => 'approved', 4 => '2025-01-02 00:00:00', 5 => 99],
        ] as $name => $values) {
            throws(PDOException::class, static fn () => $insert($values));
        }
        $insert([0 => 3, 2 => 'rejected', 4 => '2025-01-02 00:00:00', 5 => 1, 7 => 'No seats.']);
        $insert([0 => 3, 1 => $sid, 2 => 'dropped', 6 => '2025-01-02 00:00:00']);                          // dropped with no decision is valid
        $history = static fn (array $v) => $owner->prepare('INSERT INTO enrollment_history (enrollment_id, seq, at, action, actor_id, reason) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(array_replace([1, 1, '2025-01-01 00:00:00', 'requested', 1, null], $v));
        $history([]);
        foreach (['duplicate seq' => [], 'seq 0' => [1 => 0], 'bad action' => [1 => 2, 3 => 'done'], 'rejected without reason' => [1 => 2, 3 => 'rejected'],
            'empty reason' => [1 => 2, 5 => ''], 'unknown enrollment' => [0 => 999], 'unknown actor' => [1 => 2, 4 => 99]] as $name => $values) {
            throws(PDOException::class, static fn () => $history($values));
        }
        $history([1 => 2, 3 => 'rejected', 5 => 'Because.']);
        // The enrollment lock row is reused: V0008 adds the grades lock row; V0007 added none.
        same(['auth', 'enrollment', 'faculty', 'grades', 'students'], $owner->query('SELECT name FROM repository_locks ORDER BY name')->fetchAll(PDO::FETCH_COLUMN));
    });

    test('T-22 MySQL: the app account cannot delete enrollments or rewrite history', static function (): void {
        [$repo, $owner] = t22Mysql();
        [, [$s1]] = t22Catalog($repo);
        $repo->requestEnrollment(1, $s1->id(), catalogTime(), 3);
        $app = MySQLTestSupport::connectApp();
        throws(PDOException::class, static fn () => $app->exec('DELETE FROM enrollments'));
        throws(PDOException::class, static fn () => $app->exec('DELETE FROM enrollment_history'));
        throws(PDOException::class, static fn () => $app->exec("UPDATE enrollment_history SET action = 'dropped'"));
        same(1, (int) $owner->query('SELECT COUNT(*) FROM enrollments')->fetchColumn());
        same(1, (int) $owner->query('SELECT COUNT(*) FROM enrollment_history')->fetchColumn());
    });

    test('T-22 MySQL: a failed write leaves no partial state', static function (): void {
        [$repo, $owner] = t22Mysql();
        [, [$s1]] = t22Catalog($repo);
        // The student FK is only a backstop (the service checks students); it surfaces as the generic error, with no row or history.
        same('Catalog repository unavailable.', throws(RuntimeException::class, fn () => $repo->requestEnrollment(99, $s1->id(), catalogTime(), 3))->getMessage());
        same([0, 0], [(int) $owner->query('SELECT COUNT(*) FROM enrollments')->fetchColumn(), (int) $owner->query('SELECT COUNT(*) FROM enrollment_history')->fetchColumn()]);
        // A corrupt stored timestamp fails closed on read.
        $e = $repo->requestEnrollment(1, $s1->id(), catalogTime(), 3);
        $owner->exec('SET SESSION sql_mode = ""');
        $owner->exec("UPDATE enrollments SET requested_at = '0000-00-00 00:00:00' WHERE id = " . $e->id());
        $error = throws(RuntimeException::class, fn () => $repo->findEnrollment($e->id()));
        same(['Invalid stored enrollment.', null], [$error->getMessage(), $error->getPrevious()]);
    });

    test('T-22 MySQL: verifier reports the new enrollment checks and stays clean on valid data', static function (): void {
        [$repo, $owner] = t22Mysql();
        [, [$s1, $s2, $s3]] = t22Catalog($repo);
        $repo->updateSection($s1->id(), ['capacity' => 5], catalogTime());
        $a = $repo->requestEnrollment(1, $s1->id(), catalogTime(), 3);
        $repo->decideEnrollment($a->id(), 'approved', null, catalogTime(), 1);
        $dropped = $repo->requestEnrollment(2, $s3->id(), catalogTime(), 4);
        $repo->dropEnrollment($dropped->id(), 2, catalogTime(), 4);
        $verify = static fn (): array => array_filter((new IntegrityVerifier($owner))->verify(), static fn (array $ids): bool => $ids !== []);
        same(35, count((new IntegrityVerifier($owner))->verify()));
        same([], $verify());
        // approved_count drift
        $owner->exec('UPDATE sections SET approved_count = 0 WHERE id = ' . $s1->id());
        same(['enrollment_approved_count' => [$s1->id()]], $verify());
        $owner->exec('UPDATE sections SET approved_count = 1 WHERE id = ' . $s1->id());
        // history seq gap and replay mismatch (the DB cannot express these)
        $owner->exec('UPDATE enrollment_history SET seq = 5 WHERE enrollment_id = ' . $a->id() . ' AND seq = 2');
        $found = $verify();
        same([$a->id()], $found['enrollment_history_seq'] ?? []);
        $owner->exec('UPDATE enrollment_history SET seq = 2 WHERE enrollment_id = ' . $a->id() . ' AND seq = 5');
        $owner->exec("UPDATE enrollment_history SET action = 'rejected', reason = 'x' WHERE enrollment_id = " . $a->id() . ' AND seq = 2');
        same(['enrollment_history_replay' => [$a->id()]], $verify());
        $owner->exec("UPDATE enrollment_history SET action = 'approved', reason = NULL WHERE enrollment_id = " . $a->id() . ' AND seq = 2');
        $owner->exec('DELETE FROM enrollment_history WHERE enrollment_id = ' . $dropped->id() . ' AND seq = 2');
        same(['enrollment_history_replay' => [$dropped->id()]], $verify());
        $owner->exec('INSERT INTO enrollment_history (enrollment_id, seq, at, action, actor_id, reason) VALUES (' . $dropped->id() . ", 2, '2025-01-01 03:04:05', 'dropped', 4, NULL)");
        same([], $verify());
        // approved-schedule clash: force two overlapping approved sections for one student (bypassing the repository)
        $owner->exec("INSERT INTO enrollments (student_id, section_id, status, requested_at, decided_at, decided_by) VALUES (1, {$s2->id()}, 'approved', '2025-01-01 03:04:05', '2025-01-01 03:04:05', 1)");
        $id = (int) $owner->lastInsertId();
        $owner->exec("INSERT INTO enrollment_history (enrollment_id, seq, at, action, actor_id) VALUES ($id, 1, '2025-01-01 03:04:05', 'requested', 3)");
        $owner->exec("INSERT INTO enrollment_history (enrollment_id, seq, at, action, actor_id) VALUES ($id, 2, '2025-01-01 03:04:05', 'approved', 1)");
        $owner->exec('UPDATE sections SET approved_count = 1 WHERE id = ' . $s2->id());
        $clash = $verify();
        same(['enrollment_schedule_clash' => [min($s1->id(), $s2->id()), max($s1->id(), $s2->id())]], $clash);
    });

    test('T-22 MySQL: JsonImporter enrollment round trip, repeat refused, corrupt source and partial failure roll back', static function (): void {
        // Source: the phase 8 fixture plus enrollments written through the mock (all fictional).
        $withEnrollments = static function (): string {
            $runtime = phase8Runtime();
            $catalog = new MockCatalogRepository(new JsonStore($runtime . '/enrollment/enrollment.json'));
            $now = '2025-01-02T03:04:05Z';
            $approved = $catalog->requestEnrollment(1, 1, $now, 3);
            $catalog->decideEnrollment($approved->id(), 'approved', null, $now, 1);
            $rejected = $catalog->requestEnrollment(2, 1, $now, 4);
            $catalog->decideEnrollment($rejected->id(), 'rejected', 'Prerequisite missing.', $now, 1);
            $catalog->requestEnrollment(1, 3, $now, 3);
            $dropped = $catalog->requestEnrollment(2, 2, $now, 4);
            $catalog->decideEnrollment($dropped->id(), 'approved', null, $now, 1);
            $catalog->dropEnrollment($dropped->id(), 2, '2025-01-03T00:00:00Z', 4);
            return $runtime;
        };
        $tables = ['enrollments', 'enrollment_history'];
        $owner = phase8FreshDatabase();
        $runtime = $withEnrollments();
        try {
            $raw = (new JsonStore($runtime . '/enrollment/enrollment.json'))->read();
            same([4, 8], [count($raw['enrollments']), count($raw['enrollment_history'])]);
            phase8ImportAll($owner, $runtime);
            same([4, 8], array_map(static fn (string $t): int => (int) $owner->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn(), $tables));
            $sql = new MySQLCatalogRepository($owner);
            $mock = new MockCatalogRepository(new JsonStore($runtime . '/enrollment/enrollment.json'));
            $rows = static fn ($r): array => array_map(static fn ($e): array => $e->toArray(true), $r->searchEnrollments([], 1, 50)['enrollments']);
            same($rows($mock), $rows($sql));
            same(array_column($raw['enrollments'], 'id'), array_map(static fn ($e): int => $e['id'], $rows($sql)));
            same(array_column($raw['sections'], 'approved_count', 'id'), array_map('intval', $owner->query('SELECT id, approved_count FROM sections ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR)));
            same($mock->approvedStudentIds(1), $sql->approvedStudentIds(1));
            [$exit, $stdout] = phase8Verify($runtime);
            same(0, $exit);
            check(!str_contains($stdout, 'FAIL') && str_contains($stdout, 'enrollment_approved_count PASS'), 'verify.php must pass every enrollment check.');
            // A repeat is refused and changes nothing.
            $before = hash('sha256', serialize($owner->query('SELECT * FROM enrollment_history ORDER BY enrollment_id, seq')->fetchAll()));
            check(phase8Import($runtime, 'enrollment')[0] !== 0, 'A repeat import must be refused.');
            same(4, (int) $owner->query('SELECT COUNT(*) FROM enrollments')->fetchColumn());
            same($before, hash('sha256', serialize($owner->query('SELECT * FROM enrollment_history ORDER BY enrollment_id, seq')->fetchAll())));
        } finally { removeTestDirectory($runtime); }

        // Corrupt sources are refused and leave every enrollment table and the lock untouched.
        foreach (['orphan student' => static function (array &$d): void { $d['enrollments'][0]['student_id'] = 999; },
            'history seq gap' => static function (array &$d): void { $d['enrollment_history'][1]['seq'] = 7; },
            'approved_count drift' => static function (array &$d): void { $d['sections'][0]['approved_count'] = 0; },
            'bad timestamp' => static function (array &$d): void { $d['enrollments'][0]['requested_at'] = '2025-02-30T00:00:00Z'; },
            'rejected without reason' => static function (array &$d): void { $d['enrollments'][1]['reason'] = null; },
            'unexpected key' => static function (array &$d): void { $d['enrollment_notes'] = []; }] as $name => $corrupt) {
            $owner = phase8FreshDatabase();
            $runtime = $withEnrollments();
            try {
                same(0, phase8Import($runtime, 'auth')[0]);
                phase8SeedFaculty($owner);
                same(0, phase8Import($runtime, 'students')[0]);
                (new JsonStore($runtime . '/enrollment/enrollment.json'))->transaction($corrupt);
                $source = phase8SourceHashes($runtime);
                check(phase8Import($runtime, 'enrollment')[0] !== 0, $name . ': import must be refused.');
                foreach (array_merge($tables, ['terms', 'sections', 'section_instructor_history']) as $table) {
                    same(0, (int) $owner->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
                }
                same(0, (int) $owner->query("SELECT last_seq FROM repository_locks WHERE name = 'enrollment'")->fetchColumn());
                same($source, phase8SourceHashes($runtime));
            } finally { removeTestDirectory($runtime); }
        }
    });

    test('ENR-08 SR-10 MySQL: two parallel approvals for the last seat leave exactly one winner, a full section and consistent history (repeated)', static function (): void {
        require_once __DIR__ . '/MySQLConcurrencySupport.php';
        $owner = mysqlConcurrencyFixture();   // reset + migrate + users 1-12 (9-12 students), faculty, programs
        $owner->exec("INSERT INTO students (id, user_id, student_number, first_name, middle_name, last_name, program_code, year_level, academic_status, admission_year, created_at, updated_at) "
            . "VALUES (1, 9, 'RACE-S1', 'Race', NULL, 'One', 'BSCS', 1, 'active', 2025, '2025-01-01 03:04:05', '2025-01-01 03:04:05'), "
            . "(2, 10, 'RACE-S2', 'Race', NULL, 'Two', 'BSCS', 1, 'active', 2025, '2025-01-01 03:04:05', '2025-01-01 03:04:05'), "
            . "(3, 11, 'RACE-S3', 'Race', NULL, 'Three', 'BSCS', 1, 'active', 2025, '2025-01-01 03:04:05', '2025-01-01 03:04:05')");
        MySQLTestSupport::completeFixture($owner, []); // roles come from mysqlConcurrencyFixture; students need history
        $repo = new MySQLCatalogRepository(MySQLTestSupport::connectApp());
        $term = $repo->createTerm(catalogTermFields('2025-R', ['enrollment_open' => true]), catalogTime());
        for ($round = 1; $round <= 5; $round++) {
            // Capacity 2: student 3 already holds one seat, students 1 and 2 race for the last one.
            $section = $repo->createSection(catalogSectionFields($term->id(), 'RACE' . $round, 'A',
                ['capacity' => 2, 'delivery_mode' => 'tba', 'meetings' => []]), catalogTime());
            $first = $repo->requestEnrollment(3, $section->id(), catalogTime(), 11);
            $repo->decideEnrollment($first->id(), 'approved', null, catalogTime(), 1);
            $a = $repo->requestEnrollment(1, $section->id(), catalogTime(), 9);
            $b = $repo->requestEnrollment(2, $section->id(), catalogTime(), 10);
            $results = t22ApproveRace([[$a->id(), 1], [$b->id(), 1]]);
            same(1, count(array_filter($results, static fn (array $r): bool => $r['ok'])));
            $loser = array_values(array_filter($results, static fn (array $r): bool => !$r['ok']))[0];
            same([409, ['status' => 'This section is full.']], [$loser['status'], (array) $loser['errors']]);
            $id = $section->id();
            same([2, 2], [(int) $owner->query('SELECT approved_count FROM sections WHERE id = ' . $id)->fetchColumn(),
                (int) $owner->query("SELECT COUNT(*) FROM enrollments WHERE section_id = $id AND status = 'approved'")->fetchColumn()]);
            same(1, (int) $owner->query("SELECT COUNT(*) FROM enrollments WHERE section_id = $id AND status = 'pending'")->fetchColumn());
            // Winner: requested, approved. Loser: requested only, still pending. Every history is gap-free from seq 1.
            $histories = [];
            foreach ($owner->query("SELECT e.id, e.status, GROUP_CONCAT(h.seq ORDER BY h.seq) AS seqs, GROUP_CONCAT(h.action ORDER BY h.seq) AS actions "
                . "FROM enrollments e JOIN enrollment_history h ON h.enrollment_id = e.id WHERE e.section_id = $id AND e.student_id IN (1, 2) GROUP BY e.id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $histories[$row['status']] = [$row['seqs'], $row['actions']];
            }
            ksort($histories); // rows come in enrollment-id order, and either racer may be the winner
            same(['approved' => ['1,2', 'requested,approved'], 'pending' => ['1', 'requested']], $histories);
        }
        $failed = array_filter((new IntegrityVerifier($owner))->verify(), static fn (array $ids): bool => $ids !== []);
        same([], $failed);
    });
}

/** Starts one grade_mysql_worker 'approve' process per [enrollment_id, actor_id], releases them together and returns the JSON results in job order. */
function t22ApproveRace(array $jobs): array
{
    $dir = tempDirectory();
    $workers = [];
    try {
        foreach ($jobs as $index => [$enrollmentId, $actorId]) {
            $command = [PHP_BINARY, __DIR__ . '/fixtures/grade_mysql_worker.php', $dir . '/start', $dir . '/ready-' . $index, 'approve',
                json_encode(['enrollment_id' => $enrollmentId, 'actor_id' => $actorId], JSON_THROW_ON_ERROR)];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
            check(is_resource($process), 'Unable to start MySQL approval worker.');
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 15;
        while (count(glob($dir . '/ready-*') ?: []) < count($jobs)) {
            check(microtime(true) < $deadline, 'MySQL approval workers did not become ready.');
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
