<?php
declare(strict_types=1);

// UNVERIFIED: written without being executed (compromised host, owner forbids running tests).

use App\Core\Request;
use App\Faculty\Controllers\GradeController;
use App\Faculty\Services\GradeScale;

require_once __DIR__ . '/GradeSupport.php';

test('Grade scale: empty is unconfirmed, exact match, official() is unconfirmed', function (): void {
    $scale = new GradeScale(['1.00', 'INC']);
    same([true, true, false, false, false], [$scale->isConfirmed(), $scale->allows('1.00'), $scale->allows('1.0'), $scale->allows(' INC'), $scale->allows('inc')]);
    same([false, false], [(new GradeScale([]))->isConfirmed(), GradeScale::official()->isConfirmed()]);
    same(false, GradeScale::official()->allows('1.00'));
});

test('Grade scale demo(): exactly the 12 owner-approved demo codes, exact match, official() stays empty', function (): void {
    $codes = ['1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00', '5.00', 'INC', 'DRP'];
    $demo = GradeScale::demo();
    same(true, $demo->isConfirmed());
    foreach ($codes as $code) { same(true, $demo->allows($code)); }
    $ref = new ReflectionProperty(GradeScale::class, 'values');
    same($codes, $ref->getValue($demo));
    foreach (['1.0', '1', '3.25', '4.00', 'inc', 'Drp', ' INC', '5', ''] as $bad) { same(false, $demo->allows($bad)); }
    same(false, GradeScale::official()->isConfirmed());
    same(false, GradeScale::official()->allows('1.00'));
});

test('Grades: direct mock repository writes reject values outside V0010 without changing the store', function (): void {
    $dir = tempDirectory();
    try {
        $f = gradeFixture($dir);
        gradeFailure(fn () => $f['repo']->saveDraft(1, 1, '3.25', null, '2026-11-26T00:00:00Z', 3), 422, 'value');
        check(!file_exists($dir . '/faculty/grades.json'), 'An invalid direct write must not create a grade store.');
        gradeSubmitAll($f);
        $f['service']->finalize($f['admin'], 1);
        $seq = $f['repo']->findGrade(1)->seq();
        $before = gradeStoreBytes($dir);
        gradeFailure(fn () => $f['repo']->correct(1, '3.25', 'Recount', $seq, '2026-11-26T00:00:00Z', 2), 422, 'value');
        same($before, gradeStoreBytes($dir));
    } finally { removeTestDirectory($dir); }
});

// Invariant (GRADE_SCALE_ACTIVATION.md): while GradeScale::official() is empty, every grade entry (enter) and correction (correct) path is 409 grade_scale and writes nothing.
test('Grades: an unconfirmed scale fails closed (409 grade_scale) on entry and corrections and writes nothing', function (): void {
    $dir = tempDirectory();
    try {
        $f = gradeFixture($dir, GradeScale::official());
        gradeFailure(fn () => $f['service']->enter($f['faculty'], 1, 1, ['value' => '1.00']), 409, 'grade_scale');
        gradeFailure(fn () => $f['service']->correct($f['registrar'], 1, ['value' => '1.00', 'reason' => 'x', 'expected_seq' => 1]), 409, 'grade_scale');
        check(!file_exists($dir . '/faculty/grades.json'), 'No grade store may be written.');
        same(['grades' => [], 'missing' => [1, 2, 3]], $f['service']->classGrades($f['faculty'], 1));
    } finally { removeTestDirectory($dir); }
});

test('Grades: entry rules, roster check, validation and one history row per change', function (): void {
    $dir = tempDirectory();
    try {
        $f = gradeFixture($dir, null, [1, 2]);
        $s = $f['service'];
        $created = $s->enter($f['faculty'], 1, 1, ['value' => '1.25', 'remarks' => 'note']);
        same(true, $created['created']);
        same(['id', 'section_id', 'student_id', 'value', 'remarks', 'status', 'updated_at', 'seq'], array_keys($created['grade']));
        same(['draft', 1], [$created['grade']['status'], $created['grade']['seq']]);
        $updated = $s->enter($f['faculty'], 1, 1, ['value' => '2.00']);
        same([false, '2.00', null, 2], [$updated['created'], $updated['grade']['value'], $updated['grade']['remarks'], $updated['grade']['seq']]);
        gradeFailure(fn () => $s->enter($f['faculty'], 1, 3, ['value' => '1.00']), 404);      // cara has no approved enrollment
        gradeFailure(fn () => $s->enter($f['faculty'], 1, 99, ['value' => '1.00']), 404);
        $before = gradeStoreBytes($dir);
        foreach ([[['value' => '9.99'], 'value'], [['value' => 1.0], 'value'], [[], 'value'], [['value' => '1.00', 'student_id' => 2], 'student_id'],
                  [['value' => '1.00', 'remarks' => str_repeat('a', 256)], 'remarks'], [['value' => '1.00', 'remarks' => "a\x07"], 'remarks']] as [$input, $key]) {
            gradeFailure(fn () => $s->enter($f['faculty'], 1, 2, $input), 422, $key);
        }
        same($before, gradeStoreBytes($dir));
        same(['entered', 'updated'], array_column(gradeHistory($dir), 'action'));
        same([[null, '1.25'], ['1.25', '2.00']], array_map(fn ($h) => [$h['old_value'], $h['new_value']], gradeHistory($dir)));
    } finally { removeTestDirectory($dir); }
});

test('Grades: submit needs every roster student graded, then locks editing; finalize then locks everything', function (): void {
    $dir = tempDirectory();
    try {
        $f = gradeFixture($dir);
        $s = $f['service'];
        same([1, 2, 3], gradeFailure(fn () => $s->submit($f['faculty'], 1), 409, 'grades_missing')['grades_missing']);   // nobody graded
        $s->enter($f['faculty'], 1, 1, ['value' => '1.00']);
        $s->enter($f['faculty'], 1, 2, ['value' => '1.25']);
        same([3], gradeFailure(fn () => $s->submit($f['faculty'], 1), 409, 'grades_missing')['grades_missing']);
        $s->enter($f['faculty'], 1, 3, ['value' => '5.00']);
        $view = $s->submit($f['faculty'], 1);
        same(['submitted', 'submitted', 'submitted'], array_column($view['grades'], 'status'));
        gradeFailure(fn () => $s->enter($f['faculty'], 1, 1, ['value' => '2.00']), 409, 'status');
        gradeFailure(fn () => $s->submit($f['faculty'], 1), 409, 'status');             // double submit
        $final = $s->finalize($f['registrar'], 1);
        same(['finalized', 'finalized', 'finalized'], array_column($final['grades'], 'status'));
        gradeFailure(fn () => $s->enter($f['faculty'], 1, 1, ['value' => '2.00']), 409, 'status');
        gradeFailure(fn () => $s->finalize($f['admin'], 1), 409, 'status');             // nothing left to finalize
        same(['entered', 'entered', 'entered', 'submitted', 'submitted', 'submitted', 'finalized', 'finalized', 'finalized'],
            array_column(gradeHistory($dir), 'action'));
        same(range(1, 9), array_column(gradeHistory($dir), 'seq'));
    } finally { removeTestDirectory($dir); }
});

test('Grades: registrar return-to-draft needs a reason, a submitted state, and forces a resubmit before finalize', function (): void {
    $dir = tempDirectory();
    try {
        $f = gradeFixture($dir);
        $s = $f['service'];
        gradeFailure(fn () => $s->returnToDraft($f['registrar'], 1, ['reason' => 'redo']), 409, 'status');   // nothing submitted yet
        gradeSubmitAll($f);
        foreach ([[[], 'reason'], [['reason' => ''], 'reason'], [['reason' => str_repeat('a', 256)], 'reason'], [['reason' => 'x', 'extra' => 1], 'extra']] as [$input, $key]) {
            gradeFailure(fn () => $s->returnToDraft($f['registrar'], 1, $input), 422, $key);
        }
        gradeFailure(fn () => $s->returnToDraft($f['faculty'], 1, ['reason' => 'redo']), 403);
        gradeFailure(fn () => $s->returnToDraft($f['ana'], 1, ['reason' => 'redo']), 403);
        gradeFailure(fn () => $s->returnToDraft($f['registrar'], 99, ['reason' => 'redo']), 404);
        $view = $s->returnToDraft($f['registrar'], 1, ['reason' => 'Recheck exam 2']);
        same(['draft', 'draft', 'draft'], array_column($view['grades'], 'status'));
        $returned = array_values(array_filter(gradeHistory($dir), fn ($h) => $h['action'] === 'returned'));
        same([3, 'Recheck exam 2'], [count($returned), $returned[0]['reason']]);
        gradeFailure(fn () => $s->finalize($f['admin'], 1), 409, 'grades_missing');   // drafts do not count as submitted
        $s->submit($f['faculty'], 1);
        same(3, count($s->finalize($f['admin'], 1)['grades']));
        gradeFailure(fn () => $s->returnToDraft($f['registrar'], 1, ['reason' => 'late']), 409, 'status');   // finalized grades cannot be returned
    } finally { removeTestDirectory($dir); }
});

test('Grades: return-to-draft keeps finalize separation of duties (assigned instructor or entrant -> 403, nothing written)', function (): void {
    $dir = tempDirectory();
    try {
        // owner decision 2026-11-25
        $f = gradeFixture($dir);
        $s = $f['service'];
        gradeSubmitAll($f);
        $before = gradeStoreBytes($dir);
        $f['directory']->byUser[2] = new App\Faculty\Contracts\FacultySummary(10, 2, 'EMP-010', 'Reyes, Ana', 'inactive');   // registrar is also listed on the section
        gradeFailure(fn () => $s->returnToDraft($f['registrar'], 1, ['reason' => 'redo']), 403);
        unset($f['directory']->byUser[2]);
        same($before, gradeStoreBytes($dir));
        $s->returnToDraft($f['registrar'], 1, ['reason' => 'Unrelated registrar']);     // unrelated registrar still succeeds
        $returned = array_values(array_filter(gradeHistory($dir), fn ($h) => $h['action'] === 'returned'));
        same([3, 'Unrelated registrar', 2], [count($returned), $returned[0]['reason'], $returned[0]['actor_id']]);
        // the registrar entered a grade here: refused under the grades lock, at service and repository level
        $f['repo']->saveDraft(1, 1, '2.00', null, '2026-11-25T00:00:00Z', 2);
        $s->submit($f['faculty'], 1);
        $before = gradeStoreBytes($dir);
        gradeFailure(fn () => $s->returnToDraft($f['registrar'], 1, ['reason' => 'redo']), 403);
        gradeFailure(fn () => $f['repo']->returnSection(1, 'redo', '2026-11-25T00:00:00Z', 2), 403);
        same($before, gradeStoreBytes($dir));
        same(3, count($s->returnToDraft($f['admin'], 1, ['reason' => 'Admin'])['grades']));   // admin: neither assigned nor an entrant
    } finally { removeTestDirectory($dir); }
});

test('Grades: late approval before finalization blocks finalize; after finalization the student is graded and finalized alone', function (): void {
    $dir = tempDirectory();
    try {
        $f = gradeFixture($dir, null, [1, 2]);
        $s = $f['service'];
        gradeSubmitAll($f, [1, 2]);
        // before finalization: cara is approved late and has no grade
        $late = $f['enroll']->request($f['cara'], ['section_id' => 1])['id'];
        $f['enroll']->decide($f['registrar'], $late, ['status' => 'approved']);
        same([3], gradeFailure(fn () => $s->finalize($f['admin'], 1), 409, 'grades_missing')['grades_missing']);
        same([3], $s->sectionGrades($f['registrar'], 1)['missing']);
        $s->enter($f['faculty'], 1, 3, ['value' => '2.00']);
        gradeFailure(fn () => $s->finalize($f['admin'], 1), 409, 'grades_missing');        // draft is not submitted
        $s->submit($f['faculty'], 1);
        $s->finalize($f['admin'], 1);
        // a finalized grade survives a later drop
        $f['enroll']->drop($f['cara'], $late);
        same(3, count($s->sectionGrades($f['registrar'], 1)['grades']));                   // the finalized grade survives the drop
    } finally { removeTestDirectory($dir); }
});

test('Grades: a student approved after finalization appears as missing, is graded alone, and only that grade is finalized', function (): void {
    $dir = tempDirectory();
    try {
        $f = gradeFixture($dir, null, [1, 2]);
        $s = $f['service'];
        gradeSubmitAll($f, [1, 2]);
        $s->finalize($f['admin'], 1);
        $historyBefore = count(gradeHistory($dir));
        $late = $f['enroll']->request($f['cara'], ['section_id' => 1])['id'];
        $f['enroll']->decide($f['registrar'], $late, ['status' => 'approved']);
        same([3], $s->sectionGrades($f['registrar'], 1)['missing']);
        $s->enter($f['faculty'], 1, 3, ['value' => '5.00']);
        $s->submit($f['faculty'], 1);
        $view = $s->finalize($f['admin'], 1);
        same([], $view['missing']);
        same(['finalized', 'finalized', 'finalized'], array_column($view['grades'], 'status'));
        $newRows = array_slice(gradeHistory($dir), $historyBefore);
        same(['entered', 'submitted', 'finalized'], array_column($newRows, 'action'));
        same([3, 3, 3], array_column($newRows, 'student_id'));        // existing finalized grades untouched
    } finally { removeTestDirectory($dir); }
});

test('Grades: corrections need a reason and a current expected_seq; stale or malformed tokens never write', function (): void {
    $dir = tempDirectory();
    try {
        $f = gradeFixture($dir);
        $s = $f['service'];
        gradeSubmitAll($f);
        gradeFailure(fn () => $s->correct($f['registrar'], 1, ['value' => '2.00', 'reason' => 'x', 'expected_seq' => 1]), 409, 'status');   // not finalized yet
        $s->finalize($f['admin'], 1);
        $seq = $s->sectionGrades($f['registrar'], 1)['grades'][0]['seq'];
        same(7, $seq);                       // entered x3 (1-3), submitted x3 (4-6), finalized grade 1 = 7
        $before = gradeStoreBytes($dir);
        foreach ([[['value' => '2.00', 'reason' => 'x'], 'expected_seq'], [['value' => '2.00', 'reason' => 'x', 'expected_seq' => '7'], 'expected_seq'],
                  [['value' => '2.00', 'reason' => 'x', 'expected_seq' => 0], 'expected_seq'], [['value' => '2.00', 'reason' => 'x', 'expected_seq' => 7.0], 'expected_seq'],
                  [['value' => '2.00', 'expected_seq' => 7], 'reason'], [['value' => '2.00', 'reason' => '', 'expected_seq' => 7], 'reason'],
                  [['reason' => 'x', 'expected_seq' => 7], 'value'], [['value' => '3.00', 'reason' => 'x', 'expected_seq' => 7], 'value'],
                  [['value' => '2.00', 'reason' => 'x', 'expected_seq' => 7, 'status' => 'draft'], 'status']] as [$input, $key]) {
            gradeFailure(fn () => $s->correct($f['registrar'], 1, $input), 422, $key);
        }
        gradeFailure(fn () => $s->correct($f['registrar'], 1, ['value' => '2.00', 'reason' => 'x', 'expected_seq' => $seq - 1]), 409, 'stale_grade');
        gradeFailure(fn () => $s->correct($f['registrar'], 1, ['value' => '1.25', 'reason' => 'x', 'expected_seq' => $seq]), 409, 'value');   // same value
        gradeFailure(fn () => $s->correct($f['registrar'], 99, ['value' => '2.00', 'reason' => 'x', 'expected_seq' => $seq]), 404);
        same($before, gradeStoreBytes($dir));
        $done = $s->correct($f['registrar'], 1, ['value' => '2.00', 'reason' => 'Regrade', 'expected_seq' => $seq]);
        same(['2.00', 'finalized', 10], [$done['grade']['value'], $done['grade']['status'], $done['grade']['seq']]);
        gradeFailure(fn () => $s->correct($f['registrar'], 1, ['value' => '5.00', 'reason' => 'again', 'expected_seq' => $seq]), 409, 'stale_grade');
        $history = gradeHistory($dir);
        $last = end($history);
        same(['corrected', '1.25', '2.00', 'Regrade', 2], [$last['action'], $last['old_value'], $last['new_value'], $last['reason'], $last['actor_id']]);
    } finally { removeTestDirectory($dir); }
});

test('Grades: separation of duties (assigned section member, or anyone who entered grades) and role gates', function (): void {
    $dir = tempDirectory();
    try {
        $f = gradeFixture($dir);
        $s = $f['service'];
        gradeSubmitAll($f);
        // (a) the registrar account also holds an instructor profile for this section
        $f['directory']->byUser[2] = new App\Faculty\Contracts\FacultySummary(10, 2, 'EMP-010', 'Reyes, Ana', 'inactive');
        gradeFailure(fn () => $s->finalize($f['registrar'], 1), 403);
        unset($f['directory']->byUser[2]);
        // (b) the registrar entered a grade in this section: refused inside the lock
        $f['repo']->returnSection(1, 'reset', '2026-11-25T00:00:00Z', 2);
        $f['repo']->saveDraft(1, 1, '2.00', null, '2026-11-25T00:00:00Z', 2);
        $f['repo']->submitSection(1, [1, 2, 3], '2026-11-25T00:00:00Z', 3);
        gradeFailure(fn () => $s->finalize($f['registrar'], 1), 403);
        same(['submitted', 'submitted', 'submitted'], array_column($s->sectionGrades($f['admin'], 1)['grades'], 'status'));
        $s->finalize($f['admin'], 1);                                                     // admin neither assigned nor an entrant
        $seq = $s->sectionGrades($f['admin'], 1)['grades'][0]['seq'];
        gradeFailure(fn () => $s->correct($f['registrar'], 1, ['value' => '5.00', 'reason' => 'x', 'expected_seq' => $seq]), 403);
        $f['directory']->byUser[1] = new App\Faculty\Contracts\FacultySummary(10, 1, 'EMP-010', 'Reyes, Ana', 'active');
        gradeFailure(fn () => $s->correct($f['admin'], 1, ['value' => '5.00', 'reason' => 'x', 'expected_seq' => $seq]), 403);
        // role gates and faculty access
        gradeFailure(fn () => $s->enter($f['admin'], 1, 1, ['value' => '1.00']), 403);
        gradeFailure(fn () => $s->classGrades($f['registrar'], 1), 403);
        gradeFailure(fn () => $s->sectionGrades($f['faculty'], 1), 403);
        gradeFailure(fn () => $s->sectionGrades($f['ana'], 1), 403);
        gradeFailure(fn () => $s->finalize($f['faculty'], 1), 403);
        gradeFailure(fn () => $s->mine($f['faculty'], []), 403);
        gradeFailure(fn () => $s->sectionGrades($f['admin'], 99), 404);
        gradeFailure(fn () => $s->finalize($f['admin'], 99), 404);
        // faculty: unknown section, unassigned profile, inactive profile and no profile are all 403
        gradeFailure(fn () => $s->classGrades($f['faculty'], 99), 403);
        $profile = $f['directory']->byUser[3];
        $f['directory']->byUser[3] = new App\Faculty\Contracts\FacultySummary(11, 3, 'EMP-011', 'Cruz, Ben', 'active');
        gradeFailure(fn () => $s->classGrades($f['faculty'], 1), 403);
        $f['directory']->byUser[3] = new App\Faculty\Contracts\FacultySummary(10, 3, 'EMP-010', 'Reyes, Ana', 'inactive');
        gradeFailure(fn () => $s->classGrades($f['faculty'], 1), 403);
        unset($f['directory']->byUser[3]);
        gradeFailure(fn () => $s->enter($f['faculty'], 1, 1, ['value' => '1.00']), 403);
        $f['directory']->byUser[3] = $profile;
        same(3, count($s->classGrades($f['faculty'], 1)['grades']));
    } finally { removeTestDirectory($dir); }
});

test('Grades M1: an account that also owns a student record cannot enter, finalize or correct its own grade', function (): void {
    $dir = tempDirectory();
    try {
        // enter: the instructor account (user 3) is also student 2 (ben)
        $f = gradeFixture($dir);
        $s = $f['service'];
        $f['students']->byUser[3] = 2;
        gradeFailure(fn () => $s->enter($f['faculty'], 1, 2, ['value' => '1.00']), 403);
        check(!file_exists($dir . '/faculty/grades.json'), 'Nothing may be written.');
        same(true, $s->enter($f['faculty'], 1, 1, ['value' => '1.00'])['created']);       // other students are unaffected
        unset($f['students']->byUser[3]);
        gradeSubmitAll($f, [2, 3]);
        // finalize: the registrar account (user 2) is also student 1, who is on the roster
        $f['students']->byUser[2] = 1;
        $before = gradeStoreBytes($dir);
        gradeFailure(fn () => $s->finalize($f['registrar'], 1), 403);
        // ... and also when student 1 has dropped but still has a grade row in the section
        $f['enroll']->drop($f['ana'], $f['enrollments'][1]);
        gradeFailure(fn () => $s->finalize($f['registrar'], 1), 403);
        same($before, gradeStoreBytes($dir));
        unset($f['students']->byUser[2]);
        $s->finalize($f['admin'], 1);
        // correct: the same account cannot correct its own finalized grade
        $f['students']->byUser[2] = 1;
        $grades = $s->sectionGrades($f['admin'], 1)['grades'];
        $before = gradeStoreBytes($dir);
        gradeFailure(fn () => $s->correct($f['registrar'], $grades[0]['id'], ['value' => '5.00', 'reason' => 'x', 'expected_seq' => $grades[0]['seq']]), 403);
        same($before, gradeStoreBytes($dir));
        $other = $s->correct($f['registrar'], $grades[1]['id'], ['value' => '5.00', 'reason' => 'x', 'expected_seq' => $grades[1]['seq']]);
        same('5.00', $other['grade']['value']);                                           // someone else's grade is still allowed
    } finally { removeTestDirectory($dir); }
});

test('Grades M1: the section self check also covers submit, return-to-draft, classGrades and sectionGrades', function (): void {
    $dir = tempDirectory();
    try {
        $f = gradeFixture($dir);
        $s = $f['service'];
        $f['students']->byUser[3] = 2;            // faculty account is also student 2, on the roster
        gradeFailure(fn () => $s->submit($f['faculty'], 1), 403);
        gradeFailure(fn () => $s->classGrades($f['faculty'], 1), 403);
        unset($f['students']->byUser[3]);
        $s->enter($f['faculty'], 1, 1, ['value' => '1.00']);
        $f['students']->byUser[2] = 1;            // registrar account is also student 1, on the roster
        gradeFailure(fn () => $s->returnToDraft($f['registrar'], 1, ['reason' => 'x']), 403);
        gradeFailure(fn () => $s->sectionGrades($f['registrar'], 1), 403);
        same([2, 3], $s->sectionGrades($f['admin'], 1)['missing']);               // an unrelated staff account is unaffected
    } finally { removeTestDirectory($dir); }
});

test('Grades M1: finalizeSection re-checks the own student id under the locks (403, nothing written); null keeps the 3-arg behaviour', function (): void {
    $dir = tempDirectory();
    try {
        $f = gradeFixture($dir);
        gradeSubmitAll($f, [1, 2, 3]);
        $before = gradeStoreBytes($dir);
        gradeFailure(fn () => $f['repo']->finalizeSection(1, '2026-11-25T00:00:00Z', 2, 1), 403);   // on the locked roster
        $f['enroll']->drop($f['ana'], $f['enrollments'][1]);
        gradeFailure(fn () => $f['repo']->finalizeSection(1, '2026-11-25T00:00:00Z', 2, 1), 403);   // dropped, but has a grade row
        same($before, gradeStoreBytes($dir));
        same(3, count($f['repo']->finalizeSection(1, '2026-11-25T00:00:00Z', 2)));                  // 3-arg and unrelated id: unaffected
    } finally { removeTestDirectory($dir); }
});

test('Grades L1: returnSection refuses the actor\'s own student id under the lock (403, nothing written); null is unaffected', function (): void {
    $dir = tempDirectory();
    try {
        $f = gradeFixture($dir);
        gradeSubmitAll($f, [1, 2, 3]);
        $before = gradeStoreBytes($dir);
        gradeFailure(fn () => $f['repo']->returnSection(1, 'redo', '2026-11-25T00:00:00Z', 2, 1), 403);
        same($before, gradeStoreBytes($dir));
        same(3, count($f['repo']->returnSection(1, 'redo', '2026-11-25T00:00:00Z', 2)));
    } finally { removeTestDirectory($dir); }
});

test('Grades M1: an enrollment approved between the service pre-check and the lock is caught under the lock (403, nothing written)', function (): void {
    $dir = tempDirectory();
    try {
        $f = gradeFixture($dir, null, [2, 3]);            // ana (student 1) is not approved yet
        gradeSubmitAll($f, [2, 3]);
        $f['students']->byUser[2] = 1;                    // the registrar account is also student 1
        $racing = new class($f['repo'], function () use ($f): void {
            $id = $f['enroll']->request($f['ana'], ['section_id' => 1])['id'];
            $f['enroll']->decide($f['admin'], $id, ['status' => 'approved']);   // another registrar approves after the pre-check
        }) implements App\Faculty\Repositories\GradeRepositoryInterface {
            public function __construct(private App\Faculty\Repositories\GradeRepositoryInterface $inner, private Closure $beforeFinalize) {}
            public function findGrade(int $id): ?App\Faculty\Models\Grade { return $this->inner->findGrade($id); }
            public function forSection(int $sectionId): array { return $this->inner->forSection($sectionId); }
            public function finalizedForStudent(int $studentId): array { return $this->inner->finalizedForStudent($studentId); }
            public function saveDraft(int $sectionId, int $studentId, string $value, ?string $remarks, string $now, int $actorId): array { return $this->inner->saveDraft($sectionId, $studentId, $value, $remarks, $now, $actorId); }
            public function submitSection(int $sectionId, array $rosterIds, string $now, int $actorId): array { return $this->inner->submitSection($sectionId, $rosterIds, $now, $actorId); }
            public function returnSection(int $sectionId, string $reason, string $now, int $actorId, ?int $ownStudentId = null): array { return $this->inner->returnSection($sectionId, $reason, $now, $actorId, $ownStudentId); }
            public function finalizeSection(int $sectionId, string $now, int $actorId, ?int $ownStudentId = null): array
            {
                ($this->beforeFinalize)();
                return $this->inner->finalizeSection($sectionId, $now, $actorId, $ownStudentId);
            }
            public function correct(int $id, string $value, string $reason, int $expectedSeq, string $now, int $actorId): App\Faculty\Models\Grade { return $this->inner->correct($id, $value, $reason, $expectedSeq, $now, $actorId); }
        };
        $service = new App\Faculty\Services\GradeService($racing, gradeTestScale(),
            new App\Enrollment\Services\RepositorySectionDirectory($f['catalog'], new StubFacultyIdentity([10 => ['name' => 'Instructor', 'active' => true]])),
            $f['directory'], $f['students'], $f['reader'], new App\Auth\Services\AuthorizationService(), static fn (): int => 1_780_000_000);
        $before = gradeStoreBytes($dir);
        gradeFailure(fn () => $service->finalize($f['registrar'], 1), 403);   // without the lock-time check this would be 409 grades_missing
        same($before, gradeStoreBytes($dir));
    } finally { removeTestDirectory($dir); }
});

test('Grades L2: saving a draft re-checks the approved roster under the enrollment lock (404, nothing written)', function (): void {
    $dir = tempDirectory();
    try {
        $f = gradeFixture($dir, null, [1, 2]);
        gradeFailure(fn () => $f['repo']->saveDraft(1, 3, '1.00', null, '2026-11-25T00:00:00Z', 3), 404);   // cara was never approved
        gradeFailure(fn () => $f['repo']->saveDraft(1, 99, '1.00', null, '2026-11-25T00:00:00Z', 3), 404);
        check(!file_exists($dir . '/faculty/grades.json'), 'Nothing may be written.');
        same(true, $f['repo']->saveDraft(1, 1, '1.00', null, '2026-11-25T00:00:00Z', 3)['created']);
        $f['enroll']->drop($f['ben'], $f['enrollments'][2]);                                                  // ben leaves the roster
        $before = gradeStoreBytes($dir);
        gradeFailure(fn () => $f['repo']->saveDraft(1, 2, '1.00', null, '2026-11-25T00:00:00Z', 3), 404);
        gradeFailure(fn () => $f['service']->enter($f['faculty'], 1, 2, ['value' => '1.00']), 404);
        same($before, gradeStoreBytes($dir));
    } finally { removeTestDirectory($dir); }
});

test('Grades L3: remarks changes are recorded in the history (old/new), a remarks-only change is one row, students never see them', function (): void {
    $dir = tempDirectory();
    try {
        $f = gradeFixture($dir);
        $s = $f['service'];
        $s->enter($f['faculty'], 1, 1, ['value' => '1.25', 'remarks' => 'first']);
        $s->enter($f['faculty'], 1, 1, ['value' => '1.25', 'remarks' => 'second']);     // remarks-only change
        $s->enter($f['faculty'], 1, 1, ['value' => '1.25']);                            // remarks cleared
        $h = gradeHistory($dir);
        same(['entered', 'updated', 'updated'], array_column($h, 'action'));
        same([[null, 'first'], ['first', 'second'], ['second', null]], array_map(fn ($e) => [$e['old_remarks'], $e['new_remarks']], $h));
        $s->enter($f['faculty'], 1, 1, ['value' => '1.25', 'remarks' => 'staff only note']);
        $s->enter($f['faculty'], 1, 2, ['value' => '1.25']);
        $s->enter($f['faculty'], 1, 3, ['value' => '1.25']);
        $s->submit($f['faculty'], 1);
        $s->finalize($f['admin'], 1);
        $seq = $s->sectionGrades($f['admin'], 1)['grades'][0]['seq'];
        $s->correct($f['admin'], 1, ['value' => '2.00', 'reason' => 'Regrade', 'expected_seq' => $seq]);
        $h = gradeHistory($dir);
        foreach ($h as $e) {
            if (in_array($e['action'], ['submitted', 'returned', 'finalized'], true)) {
                same([null, null], [$e['old_remarks'], $e['new_remarks']], $e['action']);
            }
        }
        $last = end($h);
        same(['corrected', 'staff only note', 'staff only note'], [$last['action'], $last['old_remarks'], $last['new_remarks']]);
        $submitted = array_values(array_filter($h, fn ($e) => $e['action'] === 'submitted'));
        same([null, null], [$submitted[0]['old_remarks'], $submitted[0]['new_remarks']]);
        foreach ([$s->mine($f['ana'], []), $f['reader']->finalizedGradesForStudent(1)] as $response) {
            $json = json_encode($response);
            check(!str_contains($json, 'remarks') && !str_contains($json, 'staff only note') && !str_contains($json, 'second'), 'Student data leaks remarks.');
        }
    } finally { removeTestDirectory($dir); }
});

test('Grades: students see only their own finalized value, grade status and enrollment status, never remarks', function (): void {
    $dir = tempDirectory();
    try {
        $f = gradeFixture($dir);
        $s = $f['service'];
        same(['grades' => []], $s->mine($f['ana'], []));
        gradeSubmitAll($f);                                    // remarks 'staff only note' on every draft
        same(['grades' => []], $s->mine($f['ana'], []));       // submitted is invisible
        $s->finalize($f['admin'], 1);
        $mine = $s->mine($f['ana'], []);
        same([['section_id' => 1, 'term_id' => 1, 'course_code' => 'CS101', 'section_code' => 'A', 'value' => '1.25',
            'grade_status' => 'finalized', 'enrollment_status' => 'approved']], $mine['grades']);
        $seq = $s->sectionGrades($f['admin'], 1)['grades'][0]['seq'];
        $s->correct($f['admin'], 1, ['value' => '2.00', 'reason' => 'Regrade', 'expected_seq' => $seq]);
        $f['enroll']->drop($f['ana'], $f['enrollments'][1]);
        $after = $s->mine($f['ana'], []);
        same(['2.00', 'dropped'], [$after['grades'][0]['value'], $after['grades'][0]['enrollment_status']]);
        foreach ([$mine, $after, $s->mine($f['ana'], ['term_id' => '1']), $s->mine($f['ben'], []), ['counts' => $f['reader']->countsFor(1)]] as $response) {
            $json = json_encode($response);
            check(!str_contains($json, 'remarks') && !str_contains($json, 'staff only note') && !str_contains($json, 'Regrade'), 'Student response leaks staff data.');
        }
        same(['finalized' => 1, 'finalized_dropped' => 1], $f['reader']->countsFor(1));
        same(null, (new App\Faculty\Services\RepositoryGradeReader($f['repo'], $f['catalog']))->countsFor(99));   // ACR-08 / T-32 §247: none = unavailable
        same([], $s->mine($f['ana'], ['term_id' => '2'])['grades']);
        same(1, count($s->mine($f['ben'], [])['grades']));      // own grade only
        gradeFailure(fn () => $s->mine($f['ana'], ['student_id' => '2']), 422, 'student_id');
        gradeFailure(fn () => $s->mine($f['ana'], ['term_id' => '0']), 422, 'term_id');
        same(['grades' => []], $s->mine($f['ana'], ['term_id' => '9']));
    } finally { removeTestDirectory($dir); }
});

// Owner decision 2026-11-26 (T-32 plan §247): no finalized grade is null, shown as {available:false}, never a 0.
test('Grades (ACR-08): summary is unavailable (null) with no finalized grades, counts 1 with one, and a source failure throws', function (): void {
    $dir = tempDirectory();
    try {
        $f = gradeFixture($dir);
        same(null, $f['reader']->countsFor(1));                               // real source, nothing finalized
        gradeSubmitAll($f);
        same(null, $f['reader']->countsFor(1));                               // submitted is not finalized
        $f['service']->finalize($f['admin'], 1);
        same(['finalized' => 1, 'finalized_approved' => 1], $f['reader']->countsFor(1));
        $data = json_decode(gradeStoreBytes($dir), true);
        $data['extra'] = [];                                                   // 'unknown top key' corruption, as in the fail-closed test
        file_put_contents($dir . '/faculty/grades.json', json_encode($data));
        same('Invalid grade store.', throws(RuntimeException::class, fn () => $f['reader']->countsFor(1))->getMessage());   // failure is not zero
    } finally { removeTestDirectory($dir); }
});

test('Grades: a corrupt or hand-edited grade store fails closed and nothing is written', function (): void {
    $mutations = [
        'value and replay outside demo scale' => static function (array $d): array { $d['grades'][0]['value'] = $d['history'][0]['new_value'] = '3.25'; return $d; },
        'value differs from replay' => static function (array $d): array { $d['grades'][0]['value'] = '5.00'; return $d; },
        'status differs from replay' => static function (array $d): array { $d['grades'][0]['status'] = 'finalized'; return $d; },
        'seq gap' => static function (array $d): array { $d['history'][1]['seq'] = 5; return $d; },
        'unknown action' => static function (array $d): array { $d['history'][0]['action'] = 'deleted'; return $d; },
        'extra history key' => static function (array $d): array { $d['history'][0]['x'] = 1; return $d; },
        'corrected without reason' => static function (array $d): array { $d['history'][1]['action'] = 'corrected'; return $d; },
        'duplicate pair' => static function (array $d): array { $d['grades'][1]['student_id'] = 1; return $d; },
        'grade without history' => static function (array $d): array { $d['history'] = []; return $d; },
        'string id' => static function (array $d): array { $d['grades'][0]['id'] = '1'; return $d; },
        'unknown top key' => static function (array $d): array { $d['extra'] = []; return $d; },
        'history remarks differ from replay' => static function (array $d): array { $d['history'][0]['new_remarks'] = 'x'; return $d; },
        'old_remarks on entered' => static function (array $d): array { $d['history'][0]['old_remarks'] = 'x'; return $d; },
        'legacy history without remarks keys' => static function (array $d): array { unset($d['history'][0]['old_remarks'], $d['history'][0]['new_remarks']); return $d; },
        'remarks empty string' =>static function (array $d): array { $d['grades'][0]['remarks'] = ''; return $d; },
    ];
    foreach ($mutations as $name => $mutate) {
        $dir = tempDirectory();
        try {
            $f = gradeFixture($dir);
            $f['service']->enter($f['faculty'], 1, 1, ['value' => '1.00']);
            $f['service']->enter($f['faculty'], 1, 2, ['value' => '1.25']);
            file_put_contents($dir . '/faculty/grades.json', json_encode($mutate(json_decode(gradeStoreBytes($dir), true))));
            $before = gradeStoreBytes($dir);
            foreach ([fn () => $f['repo']->forSection(1), fn () => $f['service']->enter($f['faculty'], 1, 1, ['value' => '2.00']),
                      fn () => $f['service']->classGrades($f['faculty'], 1), fn () => $f['reader']->finalizedGradesForStudent(1)] as $action) {
                same('Invalid grade store.', throws(RuntimeException::class, $action)->getMessage(), $name);
            }
            same($before, gradeStoreBytes($dir), $name);
        } finally { removeTestDirectory($dir); }
    }
});

test('Grades: controller status codes, exact key order and remarks present in the staff view only', function (): void {
    $dir = tempDirectory();
    try {
        $f = gradeFixture($dir);
        $controller = new GradeController($f['service']);
        $request = static fn (string $m, string $body = '') => new Request($m, '/x', ['Content-Type' => 'application/json'], $body);
        $first = $controller->enter($request('PUT', '{"value":"1.25","remarks":"note"}'), ['id' => 1, 'studentId' => 1], $f['faculty']);
        $again = $controller->enter($request('PUT', '{"value":"2.00"}'), ['id' => 1, 'studentId' => 1], $f['faculty']);
        same([201, 200], [$first->status, $again->status]);
        same(['id', 'section_id', 'student_id', 'value', 'remarks', 'status', 'updated_at', 'seq'], array_keys(json_decode($first->body, true)['data']['grade']));
        same(422, httpFailure(fn () => $controller->enter($request('PUT', '{"value":"2.00","student_id":2}'), ['id' => 1, 'studentId' => 1], $f['faculty']))->status);
        same(422, httpFailure(fn () => $controller->finalize($request('POST', '{"a":1}'), ['id' => 1], $f['admin']))->status);
        $mine = $controller->mine(new Request('GET', '/api/grades/me'), [], $f['ana']);
        same(200, $mine->status);
        check(!str_contains($mine->body, 'remarks'), 'No remarks for students.');
        check(str_contains($controller->classGrades(new Request('GET', '/x'), ['id' => 1], $f['faculty'])->body, 'remarks'), 'Staff view keeps remarks.');
    } finally { removeTestDirectory($dir); }
});
