<?php
declare(strict_types=1);

use App\Core\HttpException;

require_once __DIR__ . '/EnrollmentSupport.php';

test('Enrollment store: legacy stores without enrollment keys load, and a consistent enrollment store is accepted', function (): void {
    $dir = tempDirectory();
    try {
        [, , $repository] = enrollmentFixture($dir);
        $legacy = t22Store();
        unset($legacy['enrollments'], $legacy['enrollment_history']);
        writeEnrollmentStore($dir, json_encode($legacy));
        same(null, $repository->findEnrollment(1));
        same(['enrollments' => [], 'total' => 0], $repository->searchEnrollments([], 1, 10));
        same([], $repository->approvedStudentIds(1));
        // Pending row, then an approved row with its replay; approved_count is not enforced here (E9).
        $approved = t22Enrollment(['id' => 2, 'student_id' => 2, 'status' => 'approved', 'decided_at' => '2026-11-19T00:00:00Z', 'decided_by' => 2]);
        writeEnrollmentStore($dir, json_encode(t22Store(['enrollments' => [t22Enrollment(), $approved], 'enrollment_history' => [
            t22History(), t22History(['enrollment_id' => 2]),
            t22History(['enrollment_id' => 2, 'seq' => 2, 'action' => 'approved', 'actor_id' => 2, 'at' => '2026-11-19T00:00:00Z'])]])));
        same(['pending', 'approved'], array_map(static fn ($e): string => $e->status(), $repository->searchEnrollments([], 1, 10)['enrollments']));
        same([2], $repository->approvedStudentIds(1));
        // A dropped row whose approval was undone keeps its decision fields.
        $dropped = t22Enrollment(['status' => 'dropped', 'decided_at' => '2026-11-19T00:00:00Z', 'decided_by' => 2, 'dropped_at' => '2026-11-20T00:00:00Z']);
        writeEnrollmentStore($dir, json_encode(t22Store(['enrollments' => [$dropped], 'enrollment_history' => [t22History(),
            t22History(['seq' => 2, 'action' => 'approved', 'actor_id' => 2]), t22History(['seq' => 3, 'action' => 'dropped'])]])));
        same('dropped', $repository->findEnrollment(1)->status());
    } finally { removeTestDirectory($dir); }
});

test('Enrollment store: every malformed enrollment or history shape fails closed on reads and writes without touching the file', function (): void {
    $approvedRow = t22Enrollment(['status' => 'approved', 'decided_at' => '2026-11-19T00:00:00Z', 'decided_by' => 2]);
    $second = t22Enrollment(['id' => 2]);
    $cases = [
        'enrollments not a list' => t22Store(['enrollments' => ['a' => t22Enrollment()]]),
        'enrollments a string' => t22Store(['enrollments' => 'x']),
        'history not a list' => t22Store(['enrollment_history' => ['a' => t22History()]]),
        // L4: a section whose term cannot be found fails closed on request and drop (the snapshot or the new guard refuses it).
        'section with unknown term' => (static function (): array { $s = t22Store(); $s['sections'][0]['term_id'] = 9; return $s; })(),
        'unknown section' => t22Store(['enrollments' => [t22Enrollment(['section_id' => 9])]]),
        'extra key' => t22Store(['enrollments' => [t22Enrollment() + ['term_id' => 1]]]),
        'bad status' => t22Store(['enrollments' => [t22Enrollment(['status' => 'done'])]]),
        'duplicate id' => t22Store(['enrollments' => [t22Enrollment(), t22Enrollment(['student_id' => 2])],
            'enrollment_history' => [t22History(), t22History()]]),
        'duplicate active pair' => t22Store(['enrollments' => [t22Enrollment(), $second], 'enrollment_history' => [t22History(), t22History(['enrollment_id' => 2])]]),
        'pending with decided_by' => t22Store(['enrollments' => [t22Enrollment(['decided_by' => 2])]]),
        'approved without decided_at' => t22Store(['enrollments' => [t22Enrollment(['status' => 'approved', 'decided_by' => 2])],
            'enrollment_history' => [t22History(), t22History(['seq' => 2, 'action' => 'approved'])]]),
        'rejected without reason' => t22Store(['enrollments' => [t22Enrollment(['status' => 'rejected', 'decided_at' => '2026-11-19T00:00:00Z', 'decided_by' => 2])],
            'enrollment_history' => [t22History(), t22History(['seq' => 2, 'action' => 'rejected', 'reason' => 'x'])]]),
        'dropped without dropped_at' => t22Store(['enrollments' => [t22Enrollment(['status' => 'dropped'])],
            'enrollment_history' => [t22History(), t22History(['seq' => 2, 'action' => 'dropped'])]]),
        'decided before requested' => t22Store(['enrollments' => [array_replace($approvedRow, ['decided_at' => '2026-11-01T00:00:00Z'])],
            'enrollment_history' => [t22History(), t22History(['seq' => 2, 'action' => 'approved'])]]),
        'bad timestamp' => t22Store(['enrollments' => [t22Enrollment(['requested_at' => '2026-11-18 00:00:00'])]]),
        'empty reason' => t22Store(['enrollments' => [t22Enrollment(['reason' => ''])]]),
        'enrollment without history' => t22Store(['enrollment_history' => []]),
        'history for unknown enrollment' => t22Store(['enrollment_history' => [t22History(), t22History(['enrollment_id' => 7])]]),
        'history seq gap' => t22Store(['enrollment_history' => [t22History(['seq' => 2])]]),
        'history seq repeated' => t22Store(['enrollment_history' => [t22History(), t22History()]]),
        'history extra key' => t22Store(['enrollment_history' => [t22History() + ['x' => 1]]]),
        'history first action not requested' => t22Store(['enrollments' => [$approvedRow], 'enrollment_history' => [t22History(['action' => 'approved'])]]),
        'history last action disagrees with status' => t22Store(['enrollments' => [$approvedRow], 'enrollment_history' => [t22History()]]),
        'history forbidden transition' => t22Store(['enrollments' => [$approvedRow], 'enrollment_history' => [t22History(),
            t22History(['seq' => 2, 'action' => 'approved']), t22History(['seq' => 3, 'action' => 'rejected', 'reason' => 'x'])]]),
        'history rejected without reason' => t22Store(['enrollments' => [t22Enrollment(['status' => 'rejected', 'decided_at' => '2026-11-19T00:00:00Z',
            'decided_by' => 2, 'reason' => 'x'])], 'enrollment_history' => [t22History(), t22History(['seq' => 2, 'action' => 'rejected'])]]),
        'history unknown action' => t22Store(['enrollment_history' => [t22History(['action' => 'requestd'])]]),
        'history bad actor' => t22Store(['enrollment_history' => [t22History(['actor_id' => 0])]]),
        'history bad timestamp' => t22Store(['enrollment_history' => [t22History(['at' => 'yesterday'])]]),
    ];
    foreach ($cases as $name => $store) {
        $dir = tempDirectory();
        try {
            [, , $repository] = enrollmentFixture($dir);
            $contents = json_encode($store);
            $path = writeEnrollmentStore($dir, $contents);
            $actions = [fn () => $repository->findEnrollment(1), fn () => $repository->searchEnrollments([], 1, 10),
                fn () => $repository->approvedStudentIds(1), fn () => $repository->findSection(1),
                fn () => $repository->requestEnrollment(3, 1, '2026-11-20T00:00:00Z', 4),
                fn () => $repository->decideEnrollment(1, 'approved', null, '2026-11-20T00:00:00Z', 2),
                fn () => $repository->dropEnrollment(1, 1, '2026-11-20T00:00:00Z', 4)];
            foreach ($actions as $i => $action) {
                $e = throws(RuntimeException::class, $action);
                check(!$e instanceof HttpException, "{$name}[{$i}]: must be a RuntimeException, not an HTTP error");
                check(!str_contains($e->getMessage(), $dir), "{$name}: message leaks the path");
            }
            same($contents, file_get_contents($path), $name);
        } finally { removeTestDirectory($dir); }
    }
});

test('Enrollment store: a section PATCH on a store with a corrupt enrollment fails closed', function (): void {
    $dir = tempDirectory();
    try {
        [, $sections, , $users] = enrollmentFixture($dir);
        $contents = json_encode(t22Store(['enrollment_history' => []]));
        $path = writeEnrollmentStore($dir, $contents);
        throws(RuntimeException::class, fn () => $sections->update($users->findById(2), 1, ['meetings' => [meeting('TUE')]]));
        same($contents, file_get_contents($path));
    } finally { removeTestDirectory($dir); }
});
