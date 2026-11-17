<?php
declare(strict_types=1);

use App\Core\HttpException;

require_once __DIR__ . '/EnrollmentSupport.php';

function storeTermRow(array $override = []): array
{
    return array_replace(['id' => 1, 'code' => '2026-1S', 'name' => 'First', 'start_date' => '2026-08-10', 'end_date' => '2026-12-18',
        'is_current' => false, 'enrollment_open' => false, 'created_at' => '2026-11-17T00:00:00Z', 'updated_at' => '2026-11-17T00:00:00Z'], $override);
}

function storeSectionRow(array $override = []): array
{
    return array_replace(['id' => 1, 'term_id' => 1, 'course_code' => 'CS101', 'course_title' => 'Intro', 'units' => 3, 'section_code' => 'A',
        'capacity' => 40, 'approved_count' => 0, 'meetings' => [['day' => 'MON', 'start' => '09:00', 'end' => '10:30', 'room' => 'R-101']],
        'created_at' => '2026-11-17T00:00:00Z', 'updated_at' => '2026-11-17T00:00:00Z'], $override);
}

test('Catalog store: a valid snapshot, a missing file and {} are accepted', function (): void {
    $directory = tempDirectory();
    try {
        [$terms, $sections, $repository, $users] = enrollmentFixture($directory);
        same([], $repository->terms());
        same(['sections' => [], 'total' => 0], $repository->searchSections([], 1, 10));
        writeEnrollmentStore($directory, '{}');
        same([], $repository->terms());
        $terms->create($users->findById(2), termInput());
        $sections->create($users->findById(2), sectionInput());
        writeEnrollmentStore($directory, json_encode(['terms' => [storeTermRow(['is_current' => true])], 'sections' => [storeSectionRow()]]));
        same(1, $repository->findSection(1)->id());
        // Only one of the two allowed keys present.
        writeEnrollmentStore($directory, json_encode(['terms' => [storeTermRow()]]));
        same(1, count($repository->terms()));
        same(null, $repository->findSection(1));
    } finally {
        removeTestDirectory($directory);
    }
});

test('Catalog store: every corrupt snapshot fails closed on read and on write and leaves the file byte-identical', function (): void {
    $t = storeTermRow();
    $s = storeSectionRow();
    $without = static function (array $row, string $key): array { unset($row[$key]); return $row; };
    $cases = [
        'invalid json' => '{"terms": [corrupt',
        'scalar' => '"x"',
        'terms not a list' => json_encode(['terms' => ['a' => $t], 'sections' => []]),
        'terms a string' => json_encode(['terms' => 'x', 'sections' => []]),
        'sections not a list' => json_encode(['terms' => [$t], 'sections' => (object) ['a' => $s]]),
        'term extra key' => json_encode(['terms' => [$t + ['extra' => 1]], 'sections' => []]),
        'term missing key' => json_encode(['terms' => [$without($t, 'enrollment_open')], 'sections' => []]),
        'section extra key' => json_encode(['terms' => [$t], 'sections' => [$s + ['instructors' => []]]]),
        'section missing key' => json_encode(['terms' => [$t], 'sections' => [$without($s, 'units')]]),
        'unknown top-level key' => json_encode(['terms' => [], 'sections' => [], 'enrollments' => []]),
        'duplicate term id' => json_encode(['terms' => [$t, storeTermRow(['code' => 'OTHER'])], 'sections' => []]),
        'duplicate section id' => json_encode(['terms' => [$t], 'sections' => [$s, storeSectionRow(['section_code' => 'B'])]]),
        'two current terms' => json_encode(['terms' => [storeTermRow(['is_current' => true]), storeTermRow(['id' => 2, 'code' => 'T2', 'is_current' => true])], 'sections' => []]),
        'duplicate term code' => json_encode(['terms' => [$t, storeTermRow(['id' => 2])], 'sections' => []]),
        'duplicate section triple' => json_encode(['terms' => [$t], 'sections' => [$s, storeSectionRow(['id' => 2])]]),
        'approved above capacity' => json_encode(['terms' => [$t], 'sections' => [storeSectionRow(['capacity' => 5, 'approved_count' => 6])]]),
        'negative approved' => json_encode(['terms' => [$t], 'sections' => [storeSectionRow(['approved_count' => -1])]]),
        'section term missing' => json_encode(['terms' => [$t], 'sections' => [storeSectionRow(['term_id' => 9])]]),
        'sections without any terms' => json_encode(['sections' => [$s]]),
        'meeting bad day' => json_encode(['terms' => [$t], 'sections' => [storeSectionRow(['meetings' => [['day' => 'MONDAY', 'start' => '09:00', 'end' => '10:00', 'room' => 'R']]])]]),
        'meeting overlap' => json_encode(['terms' => [$t], 'sections' => [storeSectionRow(['meetings' => [
            ['day' => 'MON', 'start' => '09:00', 'end' => '10:00', 'room' => 'R'], ['day' => 'MON', 'start' => '09:30', 'end' => '10:30', 'room' => 'R']]])]]),
        'no meetings' => json_encode(['terms' => [$t], 'sections' => [storeSectionRow(['meetings' => []])]]),
        'term dates reversed' => json_encode(['terms' => [storeTermRow(['start_date' => '2026-12-18', 'end_date' => '2026-08-10'])], 'sections' => []]),
        'term id as string' => json_encode(['terms' => [storeTermRow(['id' => '1'])], 'sections' => []]),
        'is_current as int' => json_encode(['terms' => [storeTermRow(['is_current' => 1])], 'sections' => []]),
    ];
    foreach ($cases as $name => $contents) {
        $directory = tempDirectory();
        try {
            [, , $repository, $users] = enrollmentFixture($directory);
            $path = writeEnrollmentStore($directory, $contents);
            $reads = [fn () => $repository->terms(), fn () => $repository->findTerm(1), fn () => $repository->findSection(1),
                fn () => $repository->searchSections([], 1, 10)];
            $writes = [
                fn () => $repository->createTerm(['code' => 'NEW', 'name' => 'N', 'start_date' => '2030-01-01', 'end_date' => '2030-02-01'], 'now'),
                fn () => $repository->updateTerm(1, ['name' => 'Changed'], 'now'),
                fn () => $repository->createSection(['term_id' => 1, 'course_code' => 'ZZ99', 'course_title' => 'T', 'units' => 1, 'section_code' => 'Z',
                    'capacity' => 5, 'meetings' => [['day' => 'MON', 'start' => '09:00', 'end' => '10:00', 'room' => 'R']]], 'now'),
                fn () => $repository->updateSection(1, ['capacity' => 10], 'now'),
            ];
            foreach (array_merge($reads, $writes) as $i => $action) {
                $e = throws(RuntimeException::class, $action);
                check(!$e instanceof HttpException, "{$name}[{$i}]: must be a RuntimeException, not an HTTP error");
                check(!str_contains($e->getMessage(), $directory), "{$name}: message leaks the path");
            }
            same($contents, file_get_contents($path));
            // The service layer fails closed too (never a 4xx).
            $service = new App\Enrollment\Services\TermService($repository, new App\Auth\Services\AuthorizationService());
            throws(RuntimeException::class, fn () => $service->list());
            throws(RuntimeException::class, fn () => $service->update($users->findById(2), 1, ['name' => 'X']));
            same($contents, file_get_contents($path));
        } finally {
            removeTestDirectory($directory);
        }
    }
});

test('Catalog store: failed in-lock checks (409, 422, check callback) leave the file byte-identical', function (): void {
    $directory = tempDirectory();
    try {
        [$terms, $sections, , $users] = enrollmentFixture($directory);
        $r = $users->findById(2);
        $terms->create($r, termInput(['is_current' => true]));
        $sections->create($r, sectionInput());
        $path = $directory . '/enrollment/enrollment.json';
        $before = file_get_contents($path);
        $attempts = [
            fn () => $terms->create($r, termInput(['is_current' => true])),
            fn () => $terms->update($r, 1, ['end_date' => '2020-01-01']),
            fn () => $sections->create($r, sectionInput(['term_id' => 5])),
            fn () => $sections->create($r, sectionInput()),
            fn () => $sections->update($r, 1, ['units' => 2, 'meetings' => [meeting('MON', '09:00', '09:00')]]),
        ];
        foreach ($attempts as $i => $attempt) {
            throws(HttpException::class, $attempt);
            same($before, file_get_contents($path), "attempt {$i}");
        }
        bumpApprovedCount($directory, 1, 6);
        $before = file_get_contents($path);
        same(409, httpFailure(fn () => $sections->update($r, 1, ['capacity' => 3, 'units' => 5]))->status);
        same($before, file_get_contents($path));
    } finally {
        removeTestDirectory($directory);
    }
});

test('Catalog store: repository refuses change keys outside the mutable lists', function (): void {
    $directory = tempDirectory();
    try {
        [$terms, $sections, $repository, $users] = enrollmentFixture($directory);
        $terms->create($users->findById(2), termInput());
        $sections->create($users->findById(2), sectionInput());
        foreach (['code', 'id', 'created_at'] as $key) {
            throws(InvalidArgumentException::class, fn () => $repository->updateTerm(1, [$key => 'X'], 'now'));
        }
        foreach (['term_id', 'course_code', 'section_code', 'approved_count', 'id'] as $key) {
            throws(InvalidArgumentException::class, fn () => $repository->updateSection(1, [$key => 1], 'now'));
        }
        throws(InvalidArgumentException::class, fn () => $repository->updateTerm(1, [], 'now'));
        same(0, $repository->findSection(1)->approvedCount());
    } finally {
        removeTestDirectory($directory);
    }
});

test('Catalog store: a write that cannot be committed leaves the store unchanged (Windows)', function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        return; // Uses Windows open-file replacement semantics (see StorageTest).
    }
    $directory = tempDirectory();
    $holder = null;
    try {
        [$terms, $sections, $repository, $users] = enrollmentFixture($directory);
        $r = $users->findById(2);
        $terms->create($r, termInput());
        $sections->create($r, sectionInput());
        $path = $directory . '/enrollment/enrollment.json';
        $before = file_get_contents($path);
        $holder = holdFileOpen($path, 4000, $directory . '/ready-hold');
        same('Unable to replace store.', throws(RuntimeException::class, fn () => $sections->update($r, 1, ['capacity' => 50]))->getMessage());
        same('Unable to replace store.', throws(RuntimeException::class, fn () => $terms->create($r, termInput(['code' => 'T2'])))->getMessage());
        proc_close($holder);
        $holder = null;
        same($before, file_get_contents($path));
        same([40, 1], [$repository->findSection(1)->capacity(), count($repository->terms())]);
    } finally {
        if (is_resource($holder)) {
            proc_close($holder);
        }
        removeTestDirectory($directory);
    }
});
