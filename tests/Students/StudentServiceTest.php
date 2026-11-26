<?php
declare(strict_types=1);

use App\Core\HttpException;
use App\Students\Contracts\DenyAllFacultyVisibility;
use App\Students\Services\StudentNumberPolicy;

require_once __DIR__ . '/StudentSupport.php';

function studentCase(callable $body, $visibility = null, ?string $pattern = null): void
{
    $directory = tempDirectory();
    try {
        $body($directory, ...studentFixture($directory, $visibility, $pattern));
    } finally {
        removeTestDirectory($directory);
    }
}

test('Students: registrar creates a normalized profile with defaults; admin may too', function (): void {
    studentCase(function (string $dir, $service, $repository, $users): void {
        $registrar = $users->findById(2);
        $created = $service->create($registrar, anaInput(['student_number' => ' 2026-00001 ', 'first_name' => '  Ana ', 'program_code' => 'bscs']));
        same(['id' => 1, 'user_id' => 4, 'student_number' => '2026-00001', 'first_name' => 'Ana', 'middle_name' => null,
            'last_name' => 'Reyes', 'program_code' => 'BSCS', 'year_level' => 2, 'academic_status' => 'active', 'admission_year' => null,
            'created_at' => '2026-05-28T20:26:40Z', 'updated_at' => '2026-05-28T20:26:40Z'], $created);
        $byAdmin = $service->create($users->findById(1), anaInput(['user_id' => 5, 'student_number' => 'B-2', 'first_name' => 'Ben',
            'middle_name' => 'Cruz', 'academic_status' => 'on_leave', 'admission_year' => 2024]));
        same(['on_leave', 2024, 'Cruz'], [$byAdmin['academic_status'], $byAdmin['admission_year'], $byAdmin['middle_name']]);
    });
});

test('Students: duplicate student number and duplicate account profile are 409', function (): void {
    studentCase(function (string $dir, $service, $repository, $users): void {
        $registrar = $users->findById(2);
        $service->create($registrar, anaInput());
        $error = throws(HttpException::class, fn () => $service->create($registrar, anaInput(['user_id' => 5, 'student_number' => '2026-00001 '])));
        same([409, ['student_number' => 'This student number is already in use.']], [$error->status, $error->errors]);
        $error = throws(HttpException::class, fn () => $service->create($registrar, anaInput(['student_number' => '2026-00002'])));
        same([409, ['user_id' => 'This account already has a student profile.']], [$error->status, $error->errors]);
        same(1, $repository->search([], 1, 100)['total']);
    });
});

test('Students: profile must reference an existing, active student account (no credentials are created)', function (): void {
    studentCase(function (string $dir, $service, $repository, $users): void {
        $registrar = $users->findById(2);
        foreach ([99, 6, 3, 1, 0, '4', -1] as $userId) {
            $error = throws(HttpException::class, fn () => $service->create($registrar, anaInput(['user_id' => $userId])));
            same([422, ['user_id']], [$error->status, array_keys($error->errors)]);
        }
        same(8, count($users->search('', 1, 100)['users']));
        same(0, $repository->search([], 1, 100)['total']);
    });
});

test('Students: validation of program, year level, academic status, names, admission year and number format', function (): void {
    studentCase(function (string $dir, $service, $repository, $users): void {
        $registrar = $users->findById(2);
        $cases = [
            'missing' => [['user_id' => 4], ['student_number', 'first_name', 'last_name', 'program_code', 'year_level']],
            'unknown program' => [anaInput(['program_code' => 'NOPE']), ['program_code']],
            'year above program max' => [anaInput(['program_code' => 'ASCT', 'year_level' => 3]), ['year_level']],
            'year zero' => [anaInput(['year_level' => 0]), ['year_level']],
            'year string' => [anaInput(['year_level' => '2']), ['year_level']],
            'bad status' => [anaInput(['academic_status' => 'expelled']), ['academic_status']],
            'account status is not academic' => [anaInput(['academic_status' => 'regular']), ['academic_status']],
            'empty name' => [anaInput(['first_name' => '   ']), ['first_name']],
            'long name' => [anaInput(['last_name' => str_repeat('x', 101)]), ['last_name']],
            'control char' => [anaInput(['first_name' => "An\na"]), ['first_name']],
            'bad utf8' => [anaInput(['first_name' => "\xFF"]), ['first_name']],
            'name type' => [anaInput(['middle_name' => 5]), ['middle_name']],
            'admission year' => [anaInput(['admission_year' => 1800]), ['admission_year']],
            'future admission' => [anaInput(['admission_year' => 2030]), ['admission_year']],
            'number format' => [anaInput(['student_number' => 'ab']), ['student_number']],
            'number chars' => [anaInput(['student_number' => '2026 0001']), ['student_number']],
            'number type' => [anaInput(['student_number' => 20260001]), ['student_number']],
        ];
        foreach ($cases as $name => [$input, $fields]) {
            $error = throws(HttpException::class, fn () => $service->create($registrar, $input));
            check($error->status === 422, "{$name}: expected 422, got {$error->status}");
            check(array_keys($error->errors) === $fields, "{$name}: got " . json_encode(array_keys($error->errors)));
        }
        same(0, $repository->search([], 1, 100)['total']);
    });
});

test('Students: student-number format is configurable and an invalid pattern fails closed', function (): void {
    studentCase(function (string $dir, $service, $repository, $users): void {
        $registrar = $users->findById(2);
        same('S1234567', $service->create($registrar, anaInput(['student_number' => 's1234567']))['student_number']);
        same(422, throws(HttpException::class, fn () => $service->create($registrar, anaInput(['user_id' => 5, 'student_number' => '2026-00001'])))->status);
    }, null, 'S[0-9]{7}');
    foreach (['', '(', 'a~b', str_repeat('a', 201)] as $bad) {
        throws(RuntimeException::class, fn () => new StudentNumberPolicy($bad));
    }
    // Even a permissive pattern cannot admit characters outside [A-Z0-9-/].
    same(null, (new StudentNumberPolicy('.+'))->normalize('2026_<x>'));
    same('2026/01', (new StudentNumberPolicy('.+'))->normalize('2026/01'));
});

test('Students: updates change only academic profile fields; number and account are immutable', function (): void {
    studentCase(function (string $dir, $service, $repository, $users): void {
        $registrar = $users->findById(2);
        $service->create($registrar, anaInput());
        $updated = $service->update($registrar, 1, ['academic_status' => 'graduated', 'year_level' => 4, 'middle_name' => 'Lopez']);
        same(['graduated', 4, 'Lopez', '2026-00001', 4], [$updated['academic_status'], $updated['year_level'], $updated['middle_name'],
            $updated['student_number'], $updated['user_id']]);
        // Switching to a shorter program re-validates the existing year level.
        same(['year_level'], array_keys(throws(HttpException::class, fn () => $service->update($registrar, 1, ['program_code' => 'ASCT']))->errors));
        same('ASCT', $service->update($registrar, 1, ['program_code' => 'ASCT', 'year_level' => 2])['program_code']);
        same(422, throws(HttpException::class, fn () => $service->update($registrar, 1, []))->status);
        same(422, throws(HttpException::class, fn () => $service->update($registrar, 1, ['academic_status' => 'active', 'year_level' => 9]))->status);
        same(404, throws(HttpException::class, fn () => $service->update($registrar, 99, ['year_level' => 1]))->status);
        same('active', $service->update($users->findById(1), 1, ['academic_status' => 'active'])['academic_status']);
    });
});

test('Students: self-access to own profile only; others are indistinguishable from missing', function (): void {
    studentCase(function (string $dir, $service, $repository, $users): void {
        $registrar = $users->findById(2);
        $service->create($registrar, anaInput());
        $service->create($registrar, anaInput(['user_id' => 5, 'student_number' => '2026-00002', 'first_name' => 'Ben']));
        $ana = $users->findById(4);
        same('Ana', $service->me($ana)['first_name']);
        same('Ana', $service->get($ana, 1)['first_name']);
        $hidden = throws(HttpException::class, fn () => $service->get($ana, 2));
        $missing = throws(HttpException::class, fn () => $service->get($ana, 99));
        same([404, 'Student not found.', []], [$hidden->status, $hidden->getMessage(), $hidden->errors]);
        same([$hidden->status, $hidden->getMessage(), $hidden->errors], [$missing->status, $missing->getMessage(), $missing->errors]);
        same(404, throws(HttpException::class, fn () => $service->me($users->findById(7)))->status);
        foreach ([fn () => $service->update($ana, 1, ['academic_status' => 'graduated']), fn () => $service->list($ana, []),
            fn () => $service->create($ana, anaInput(['user_id' => 7, 'student_number' => 'X-1']))] as $attempt) {
            same(403, throws(HttpException::class, $attempt)->status);
        }
        same('active', $repository->findById(1)->toArray()['academic_status']);
    });
});

test('Students: faculty see only the approved summary for verified shared sections; otherwise 404', function (): void {
    $stub = new StubFacultyVisibility();
    studentCase(function (string $dir, $service, $repository, $users) use ($stub): void {
        $registrar = $users->findById(2);
        $service->create($registrar, anaInput(['middle_name' => 'Lopez', 'admission_year' => 2025]));
        $service->create($registrar, anaInput(['user_id' => 5, 'student_number' => '2026-00002', 'first_name' => 'Ben']));
        same(['id' => 1, 'student_number' => '2026-00001', 'full_name' => 'Reyes, Ana Lopez', 'program_code' => 'BSCS', 'year_level' => 2,
            'sections' => [['section_id' => 12, 'section_code' => 'CS201-A', 'enrollment_status' => 'approved']]],
            $service->get($users->findById(3), 1));
        same(404, throws(HttpException::class, fn () => $service->get($users->findById(3), 2))->status);
        same(404, throws(HttpException::class, fn () => $service->get($users->findById(8), 1))->status);
        same(404, throws(HttpException::class, fn () => $service->get($users->findById(3), 99))->status);
        foreach ([fn () => $service->list($users->findById(3), []), fn () => $service->update($users->findById(3), 1, ['year_level' => 3])] as $attempt) {
            same(403, throws(HttpException::class, $attempt)->status);
        }
    }, $stub);
    // The missing id 99 is checked too, so existence can't be inferred from response time.
    same([[3, 1], [3, 2], [8, 1], [3, 99]], $stub->calls);
});

test('Students: only approved enrollments make a student visible to faculty', function (): void {
    studentCase(function (string $dir, $service, $repository, $users): void {
        $service->create($users->findById(2), anaInput());
        same(404, throws(HttpException::class, fn () => $service->get($users->findById(3), 1))->status);
    }, new StubFacultyVisibility([['section_id' => 12, 'section_code' => 'CS201-A', 'enrollment_status' => 'pending']]));
    studentCase(function (string $dir, $service, $repository, $users): void {
        $service->create($users->findById(2), anaInput());
        same([['section_id' => 13, 'section_code' => 'CS202-B', 'enrollment_status' => 'approved']], $service->get($users->findById(3), 1)['sections']);
    }, new StubFacultyVisibility([['section_id' => 12, 'section_code' => 'CS201-A', 'enrollment_status' => 'rejected'],
        ['section_id' => 13, 'section_code' => 'CS202-B', 'enrollment_status' => 'approved']]));
});

test('Students: names reject Unicode control and format characters (bidi overrides, zero-width, C1)', function (): void {
    studentCase(function (string $dir, $service, $repository, $users): void {
        $registrar = $users->findById(2);
        foreach (["Ana\u{202E}", "A\u{200B}na", "An\u{0085}a", "\u{FEFF}Ana", "A\u{2066}na"] as $name) {
            $error = throws(HttpException::class, fn () => $service->create($registrar, anaInput(['first_name' => $name])));
            same([422, ['first_name']], [$error->status, array_keys($error->errors)]);
        }
        same('José Ñuñez', $service->create($registrar, anaInput(['first_name' => 'José Ñuñez']))['first_name']);
    });
});

test('Students: the year-level/program check is re-run inside the lock and aborts without writing', function (): void {
    studentCase(function (string $dir, $service, $repository, $users): void {
        $service->create($users->findById(2), anaInput(['year_level' => 2]));
        // Another writer has meanwhile moved the student to a 2-year program at year 2.
        $repository->update(1, ['program_code' => 'ASCT'], '2026-01-01T00:00:00Z');
        $before = file_get_contents($dir . '/students/students.json');
        $check = static function (array $row): void {
            if ($row['program_code'] === 'ASCT' && $row['year_level'] > 2) {
                throw new HttpException(409, 'Validation failed.', ['year_level' => 'x']);
            }
        };
        same(409, throws(HttpException::class, fn () => $repository->update(1, ['year_level' => 4], '2026-01-01T00:00:00Z', $check))->status);
        same($before, file_get_contents($dir . '/students/students.json'));
    });
});

test('Students: missing or unverifiable class assignments deny faculty (deny-all default, errors, malformed answers)', function (): void {
    $answers = ['deny-all default' => new DenyAllFacultyVisibility(), 'source throws' => new StubFacultyVisibility(new RuntimeException('down')),
        'malformed section' => new StubFacultyVisibility([['section_id' => '12', 'section_code' => 'X', 'enrollment_status' => 'approved']]),
        'partly malformed' => new StubFacultyVisibility([['section_id' => 12, 'section_code' => 'X', 'enrollment_status' => 'approved'], ['section_id' => 13]])];
    foreach ($answers as $name => $visibility) {
        studentCase(function (string $dir, $service, $repository, $users) use ($name): void {
            $service->create($users->findById(2), anaInput());
            $error = throws(HttpException::class, fn () => $service->get($users->findById(3), 1));
            check($error->status === 404, "{$name}: expected 404");
        }, $visibility);
    }
});

test('Students: listing and search are staff-only, bounded and validated', function (): void {
    studentCase(function (string $dir, $service, $repository, $users): void {
        $registrar = $users->findById(2);
        $service->create($registrar, anaInput());
        $service->create($registrar, anaInput(['user_id' => 5, 'student_number' => '2026-00002', 'first_name' => 'Ben', 'last_name' => 'Santos',
            'program_code' => 'ASCT', 'year_level' => 1, 'academic_status' => 'on_leave']));
        same(2, $service->list($registrar, [])['pagination']['total']);
        same([2], array_column($service->list($registrar, ['search' => 'SANT'])['students'], 'id'));
        same([1], array_column($service->list($registrar, ['search' => '00001'])['students'], 'id'));
        same([2], array_column($service->list($users->findById(1), ['program_code' => 'asct'])['students'], 'id'));
        same([2], array_column($service->list($registrar, ['academic_status' => 'on_leave'])['students'], 'id'));
        same([2], array_column($service->list($registrar, ['page' => '2', 'per_page' => '1'])['students'], 'id'));
        foreach ([['page' => '0'], ['per_page' => '101'], ['academic_status' => 'x'], ['search' => str_repeat('a', 101)], ['search' => ['a']],
            ['program_code' => ['x']]] as $query) {
            same(422, throws(HttpException::class, fn () => $service->list($registrar, $query))->status);
        }
    });
});
