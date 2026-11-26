<?php
declare(strict_types=1);

use App\Core\HttpException;
use App\Core\JsonStore;
use App\Students\Contracts\StudentSummary;

require_once __DIR__ . '/StudentSupport.php';

function academicCase(callable $body, $enrollment = null, $grades = null): void
{
    $directory = tempDirectory();
    try {
        $body($directory, ...academicFixture($directory, $enrollment, $grades));
    } finally {
        removeTestDirectory($directory);
    }
}

test('Academic record: staff see any record; student sees own; unavailable summaries are explicit', function (): void {
    academicCase(function (string $dir, $service, $records, $repository, $users): void {
        $service->create($users->findById(2), anaInput(['middle_name' => 'Lopez', 'admission_year' => 2025]));
        $record = $records->forStudent($users->findById(2), 1, []);
        same(['id' => 1, 'student_number' => '2026-00001', 'full_name' => 'Reyes, Ana Lopez', 'program_code' => 'BSCS',
            'program_name' => 'BS Computer Science', 'year_level' => 2, 'academic_status' => 'active', 'admission_year' => 2025], $record['student']);
        same(['available' => false], $record['enrollment_summary']);
        same(['available' => false], $record['grades_summary']);
        same(['page' => 1, 'per_page' => 50, 'total' => 3], $record['history']['pagination']);
        same($record, $records->forStudent($users->findById(1), 1, []));
        same([2, 2, 2], array_column($record['history']['entries'], 'actor_id'));
        // Students see the same record without the staff account ids.
        $withoutActors = $record;
        $withoutActors['history']['entries'] = array_map(static function (array $e): array { unset($e['actor_id']); return $e; },
            $record['history']['entries']);
        same($withoutActors, $records->forStudent($users->findById(4), 1, []));
        same($withoutActors, $records->forMe($users->findById(4), []));
        check(!str_contains(json_encode($records->forMe($users->findById(4), [])), 'actor_id'), 'Students must not see actor ids.');
        check(!str_contains(json_encode($record), 'user_id'), 'Academic record must not expose the account id.');
    });
});

test('Academic record: other students, non-students and missing records all get the same 404; faculty are refused', function (): void {
    academicCase(function (string $dir, $service, $records, $repository, $users): void {
        $service->create($users->findById(2), anaInput());
        $service->create($users->findById(2), anaInput(['user_id' => 5, 'student_number' => '2026-00002']));
        $ben = $users->findById(5);
        $hidden = throws(HttpException::class, fn () => $records->forStudent($ben, 1, []));
        $missing = throws(HttpException::class, fn () => $records->forStudent($ben, 99, []));
        same([404, 'Student not found.', []], [$hidden->status, $hidden->getMessage(), $hidden->errors]);
        same([$hidden->status, $hidden->getMessage()], [$missing->status, $missing->getMessage()]);
        same(404, throws(HttpException::class, fn () => $records->forMe($users->findById(7), []))->status);
        same(403, throws(HttpException::class, fn () => $records->forStudent($users->findById(3), 1, []))->status);
        same(403, throws(HttpException::class, fn () => $records->forMe($users->findById(3), []))->status);
    });
});

test('Academic history: creation entries, one entry per changed tracked field, none for no-ops or name corrections', function (): void {
    academicCase(function (string $dir, $service, $records, $repository, $users): void {
        $registrar = $users->findById(2);
        $service->create($registrar, anaInput());
        $service->update($registrar, 1, ['first_name' => 'Anna', 'admission_year' => 2024]);
        $service->update($registrar, 1, ['academic_status' => 'active', 'year_level' => 2]);
        $service->update($registrar, 1, ['year_level' => 3]);
        $service->update($users->findById(1), 1, ['academic_status' => 'on_leave', 'program_code' => 'ASCT', 'year_level' => 1]);
        $entries = $repository->history(1, 1, 100)['entries'];
        same([
            [1, 'program_code', null, 'BSCS', 2], [2, 'year_level', null, 2, 2], [3, 'academic_status', null, 'active', 2],
            [4, 'year_level', 2, 3, 2],
            [5, 'program_code', 'BSCS', 'ASCT', 1], [6, 'year_level', 3, 1, 1], [7, 'academic_status', 'active', 'on_leave', 1],
        ], array_map(static fn (array $e): array => [$e['seq'], $e['field'], $e['from'], $e['to'], $e['actor_id']], $entries));
        same('2026-05-28T20:26:40Z', $entries[0]['at']);
    });
});

test('Academic history: ordered oldest first, paginated, and never truncated', function (): void {
    academicCase(function (string $dir, $service, $records, $repository, $users): void {
        $registrar = $users->findById(2);
        $service->create($registrar, anaInput(['year_level' => 1]));
        // 600 status changes: well past any old 500 cap.
        for ($i = 0; $i < 300; $i++) {
            $repository->update(1, ['academic_status' => 'on_leave'], '2026-01-01T00:00:00Z', null, 2);
            $repository->update(1, ['academic_status' => 'active'], '2026-01-01T00:00:00Z', null, 2);
        }
        same(603, $repository->history(1, 1, 1)['total']);
        $last = $records->forStudent($registrar, 1, ['page' => '7', 'per_page' => '100']);
        same(['page' => 7, 'per_page' => 100, 'total' => 603], $last['history']['pagination']);
        same([601, 602, 603], array_column($last['history']['entries'], 'seq'));
        same([1, 2], array_column($records->forStudent($registrar, 1, ['per_page' => '2'])['history']['entries'], 'seq'));
        same([], $records->forStudent($registrar, 1, ['page' => '99'])['history']['entries']);
        foreach ([['page' => '0'], ['per_page' => '101'], ['page' => 'x'], ['per_page' => ['1']]] as $query) {
            same(422, throws(HttpException::class, fn () => $records->forStudent($registrar, 1, $query))->status);
        }
    });
});

test('Academic history: corrupt history fails closed for reads and writes without changing the store', function (): void {
    academicCase(function (string $dir, $service, $records, $repository, $users): void {
        $service->create($users->findById(2), anaInput());
        $store = new JsonStore($dir . '/students/students.json');
        foreach ([
            static function (array &$d): void { $d['history']['1'][1]['seq'] = 9; },
            static function (array &$d): void { $d['history']['1'][0]['field'] = 'first_name'; },
            static function (array &$d): void { $d['history'] = ['x' => []]; },
            static function (array &$d): void { $d['history'] = 'oops'; },
            static function (array &$d): void { $d['history']['1'][0]['to'] = ['nested' => 'x']; },
            static function (array &$d): void { $d['history']['1'][1]['to'] = 99; },
            static function (array &$d): void { $d['history']['1'][2]['to'] = 'expelled'; },
            static function (array &$d): void { $d['history']['1'][0]['at'] = 'yesterday'; },
        ] as $corrupt) {
            $snapshot = $store->read();
            $store->transaction(static function (array &$d) use ($corrupt): void { $corrupt($d); });
            $bad = file_get_contents($dir . '/students/students.json');
            throws(RuntimeException::class, fn () => $records->forStudent($users->findById(2), 1, []));
            throws(RuntimeException::class, fn () => $service->update($users->findById(2), 1, ['year_level' => 3]));
            same($bad, file_get_contents($dir . '/students/students.json'));
            $store->transaction(static function (array &$d) use ($snapshot): void { $d = $snapshot; });
        }
    });
});

test('Academic history: a legacy non-conforming profile value aborts only its own update', function (): void {
    academicCase(function (string $dir, $service, $records, $repository, $users): void {
        $registrar = $users->findById(2);
        $service->create($registrar, anaInput());
        $service->create($registrar, anaInput(['user_id' => 5, 'student_number' => '2026-00002']));
        $store = new JsonStore($dir . '/students/students.json');
        $store->transaction(static function (array &$d): void { $d['students'][0]['program_code'] = 'bad code'; });
        $before = file_get_contents($dir . '/students/students.json');
        throws(RuntimeException::class, fn () => $repository->update(1, ['program_code' => 'ASCT'], '2026-01-01T00:00:00Z', null, 2));
        same($before, file_get_contents($dir . '/students/students.json'));
        $repository->update(2, ['year_level' => 3], '2026-01-01T00:00:00Z', null, 2);
        same(4, $repository->history(2, 1, 100)['total']);
    });
});

test('Academic summaries: verified counts are shown; anything else is an explicit unavailable state', function (): void {
    academicCase(function (string $dir, $service, $records, $repository, $users): void {
        $service->create($users->findById(2), anaInput());
        $record = $records->forStudent($users->findById(2), 1, []);
        same(['available' => true, 'counts' => ['enrolled_sections' => 5, 'units' => 18]], $record['enrollment_summary']);
        same(['available' => false], $record['grades_summary']);
    }, new StubSummarySource(['enrolled_sections' => 5, 'units' => 18]), new StubSummarySource(new RuntimeException('down')));
    foreach ([['x' => -1], ['x' => '5'], ['Bad-Key' => 1], [1, 2], [], ['x' => 1.5]] as $bad) {
        academicCase(function (string $dir, $service, $records, $repository, $users): void {
            $service->create($users->findById(2), anaInput());
            same(['available' => false], $records->forStudent($users->findById(2), 1, [])['enrollment_summary']);
        }, new StubSummarySource($bad));
    }
});

test('Program catalog: read-only, sorted, validated; a corrupt catalog fails closed', function (): void {
    academicCase(function (string $dir, $service, $records, $repository, $users): void {
        same(['programs' => [['code' => 'ASCT', 'name' => 'Associate in Computer Technology', 'max_year_level' => 2],
            ['code' => 'BSCS', 'name' => 'BS Computer Science', 'max_year_level' => 4]]], $records->programs($users->findById(3)));
        foreach (['{"programs": [{"code": "bad code", "name": "x", "max_year_level": 4}]}', '{"programs": [{"code": "X", "name": "x", "max_year_level": 99}]}',
            '{"programs": {"a": 1}}', '{corrupt'] as $contents) {
            file_put_contents($dir . '/students/programs.json', $contents);
            throws(RuntimeException::class, fn () => $records->programs($users->findById(3)));
        }
    });
});

test('StudentDirectory: summaries only; Team 3 eligibility needs active status, active account and the student role', function (): void {
    academicCase(function (string $dir, $service, $records, $repository, $users, $directory): void {
        $service->create($users->findById(2), anaInput(['middle_name' => 'Lopez']));
        $summary = $directory->findById(1);
        check($summary instanceof StudentSummary);
        same(['id' => 1, 'userId' => 4, 'studentNumber' => '2026-00001', 'fullName' => 'Reyes, Ana Lopez', 'programCode' => 'BSCS',
            'yearLevel' => 2, 'academicStatus' => 'active'], get_object_vars($summary));
        same(1, $directory->findByUserId(4)->id);
        same(null, $directory->findById(99));
        same(null, $directory->findByUserId(5));
        same(true, $directory->isEnrollmentEligible(1));
        same(false, $directory->isEnrollmentEligible(99));
        foreach (['inactive', 'graduated', 'transferred', 'on_leave'] as $status) {
            $repository->update(1, ['academic_status' => $status], '2026-01-01T00:00:00Z', null, 2);
            check($directory->isEnrollmentEligible(1) === false, "{$status} must not be eligible");
        }
        $repository->update(1, ['academic_status' => 'active'], '2026-01-01T00:00:00Z', null, 2);
        $users->transaction(static fn ($tx) => $tx->update(4, ['roles' => ['faculty']]));
        same(false, $directory->isEnrollmentEligible(1));
        $users->transaction(static fn ($tx) => $tx->update(4, ['roles' => ['student'], 'status' => 'inactive']));
        same(false, $directory->isEnrollmentEligible(1));
        // Account changes never alter the academic record (owner decision 3).
        same('active', $directory->findById(1)->academicStatus);
    });
});
