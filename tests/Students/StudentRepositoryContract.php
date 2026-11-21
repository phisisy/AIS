<?php
declare(strict_types=1);

use App\Core\HttpException;
use App\Students\Contracts\ProgramCatalogInterface;
use App\Students\Repositories\StudentRepositoryInterface;

/** Register the same cases against a fresh mock or MySQL fixture. */
function studentRepositoryContract(string $backend, callable $factory): void
{
    $case = static function (string $name, callable $body) use ($backend, $factory): void {
        test("Student repository {$backend}: {$name}", static function () use ($factory, $body): void {
            [$repository, $catalog, $cleanup] = $factory();
            try {
                $body($repository, $catalog);
            } finally {
                $cleanup();
            }
        });
    };

    $case('catalog and missing lookups', static function (StudentRepositoryInterface $repo, ProgramCatalogInterface $catalog): void {
        same(null, $repo->findById(999));
        same(null, $repo->findByUserId(999));
        same([], $repo->history(999, 1, 10)['entries']);
        same(0, $repo->history(999, 1, 10)['total']);
        same(['BSCS', 'BSIT'], array_map(static fn ($program): string => $program->code, $catalog->all()));
        same(['BSCS', 'Computer Science', 4], [$catalog->find('BSCS')->code, $catalog->find('BSCS')->name, $catalog->find('BSCS')->maxYearLevel]);
        same(null, $catalog->find('bscs'));
        same(null, $catalog->find('MISSING'));
    });

    $case('create, typed row and initial history', static function (StudentRepositoryInterface $repo): void {
        $created = $repo->create(studentContractFields(1, '2024/001'), studentContractTime(), 0);
        same(1, $created->id());
        same(1, $repo->findByUserId(1)->id());
        same($created->toArray(), $repo->findById(1)->toArray());
        same(['id', 'user_id', 'student_number', 'first_name', 'middle_name', 'last_name', 'program_code',
            'year_level', 'academic_status', 'admission_year', 'created_at', 'updated_at'], array_keys($created->toArray()));
        same(studentContractTime(), $created->toArray()['created_at']);
        $history = $repo->history(1, 1, 10);
        same(3, $history['total']);
        same(['program_code', 'year_level', 'academic_status'], array_column($history['entries'], 'field'));
        same([1, 2, 3], array_column($history['entries'], 'seq'));
        same(['seq', 'at', 'field', 'from', 'to', 'actor_id'], array_keys($history['entries'][0]));
        same([null, 'BSCS', 0], [$history['entries'][0]['from'], $history['entries'][0]['to'], $history['entries'][0]['actor_id']]);
        same([null, 1], [$history['entries'][1]['from'], $history['entries'][1]['to']]);
        same(studentContractTime(), $history['entries'][2]['at']);
    });

    $case('duplicate errors can be combined', static function (StudentRepositoryInterface $repo): void {
        $repo->create(studentContractFields(1, '2024/001'), studentContractTime());
        $error = throws(HttpException::class, static fn () => $repo->create(studentContractFields(1, '2024/001'), studentContractTime()));
        same([409, 'Validation failed.', ['user_id' => 'This account already has a student profile.',
            'student_number' => 'This student number is already in use.']], [$error->status, $error->getMessage(), $error->errors]);
        $error = throws(HttpException::class, static fn () => $repo->create(studentContractFields(2, '2024/001'), studentContractTime()));
        same(['student_number' => 'This student number is already in use.'], $error->errors);
        $error = throws(HttpException::class, static fn () => $repo->create(studentContractFields(1, '2024/002'), studentContractTime()));
        same(['user_id' => 'This account already has a student profile.'], $error->errors);
        same(1, $repo->search([], 1, 10)['total']);
        same(3, $repo->history(1, 1, 10)['total']);
    });

    $case('search filters, order, pages and literal wildcards', static function (StudentRepositoryInterface $repo): void {
        $rows = [
            studentContractFields(1, '2024/001'),
            studentContractFields(2, '2024/002', ['first_name' => 'B%_!Name', 'program_code' => 'BSIT']),
            studentContractFields(3, '2024/003', ['last_name' => 'Case', 'academic_status' => 'inactive']),
            studentContractFields(4, '2024/004', ['first_name' => 'Admin']),
        ];
        foreach ($rows as $row) {
            $repo->create($row, studentContractTime());
        }
        same([1, 2], array_map(static fn ($s): int => $s->id(), $repo->search([], 1, 2)['students']));
        same([3, 4], array_map(static fn ($s): int => $s->id(), $repo->search([], 2, 2)['students']));
        same(4, $repo->search([], 9, 2)['total']);
        same([], $repo->search([], 9, 2)['students']);
        same([2], array_map(static fn ($s): int => $s->id(), $repo->search(['search' => '%_!'], 1, 10)['students']));
        same(1, $repo->search(['search' => '  admin  '], 1, 10)['total']);
        same(1, $repo->search(['search' => '2024/002'], 1, 10)['total']);
        same(1, $repo->search(['search' => 'CASE'], 1, 10)['total']);
        same(1, $repo->search(['program_code' => 'BSIT'], 1, 10)['total']);
        same(1, $repo->search(['academic_status' => 'inactive'], 1, 10)['total']);
        same(0, $repo->search(['program_code' => 'BSIT', 'academic_status' => 'inactive'], 1, 10)['total']);
        same(0, $repo->search(['search' => 'absent'], 1, 10)['total']);
    });

    $case('update validates merged row and records each change', static function (StudentRepositoryInterface $repo): void {
        $repo->create(studentContractFields(1, '2024/001'), studentContractTime());
        $seen = null;
        $updated = $repo->update(1, ['program_code' => 'BSIT', 'year_level' => 2, 'academic_status' => 'on_leave'],
            '2024-01-02T03:04:05Z', static function (array $merged) use (&$seen): void { $seen = $merged; }, 7);
        same('BSIT', $seen['program_code']);
        same(2, $seen['year_level']);
        same('2024-01-02T03:04:05Z', $seen['updated_at']);
        same($seen, $updated->toArray());
        $entries = $repo->history(1, 1, 10)['entries'];
        same([1, 2, 3, 4, 5, 6], array_column($entries, 'seq'));
        same(['program_code', 'year_level', 'academic_status'], array_column(array_slice($entries, 3), 'field'));
        same(['BSCS', 'BSIT', 7], [$entries[3]['from'], $entries[3]['to'], $entries[3]['actor_id']]);
        same([1, 2, 7], [$entries[4]['from'], $entries[4]['to'], $entries[4]['actor_id']]);
        $repo->update(1, ['first_name' => 'Corrected', 'program_code' => 'BSIT'], '2024-01-03T03:04:05Z');
        same(6, $repo->history(1, 1, 10)['total']);
        same(2, count($repo->history(1, 2, 4)['entries']));
        same([5, 6], array_column($repo->history(1, 2, 4)['entries'], 'seq'));
    });

    $case('unsupported and missing updates', static function (StudentRepositoryInterface $repo): void {
        same('Unsupported student change.', throws(InvalidArgumentException::class,
            static fn () => $repo->update(1, [], studentContractTime()))->getMessage());
        same('Unsupported student change.', throws(InvalidArgumentException::class,
            static fn () => $repo->update(1, ['student_number' => 'OTHER'], studentContractTime()))->getMessage());
        same('Unsupported student change.', throws(InvalidArgumentException::class,
            static fn () => $repo->update(1, ['user_id' => 2], studentContractTime()))->getMessage());
        $repo->create(studentContractFields(1, '2024/001'), studentContractTime());
        $error = throws(HttpException::class, static fn () => $repo->update(999, ['first_name' => 'X'], studentContractTime()));
        same([404, 'Student not found.'], [$error->status, $error->getMessage()]);
    });

    $case('thrown check leaves row and history unchanged', static function (StudentRepositoryInterface $repo, ProgramCatalogInterface $catalog): void {
        $repo->create(studentContractFields(1, '2024/001'), studentContractTime());
        $before = $repo->findById(1)->toArray();
        $error = throws(HttpException::class, static fn () => $repo->update(1,
            ['program_code' => 'BSIT', 'year_level' => 2], '2024-01-02T03:04:05Z',
            static function (array $merged) use ($catalog): void {
                same('BSIT', $catalog->find($merged['program_code'])->code);
                throw new HttpException(409, 'Validation failed.', ['year_level' => 'Reload and retry.']);
            }, 8));
        same([409, 'Validation failed.', ['year_level' => 'Reload and retry.']], [$error->status, $error->getMessage(), $error->errors]);
        same($before, $repo->findById(1)->toArray());
        same(3, $repo->history(1, 1, 10)['total']);
        $repo->update(1, ['year_level' => 2], '2024-01-03T03:04:05Z', null, 8);
        same([1, 2, 3, 4], array_column($repo->history(1, 1, 10)['entries'], 'seq'));
        same(8, $repo->history(1, 1, 10)['entries'][3]['actor_id']);
    });
}

function studentContractTime(): string
{
    return '2024-01-01T03:04:05Z';
}

function studentContractFields(int $userId, string $number, array $overrides = []): array
{
    return array_replace(['user_id' => $userId, 'student_number' => $number, 'first_name' => 'First',
        'middle_name' => null, 'last_name' => 'Last', 'program_code' => 'BSCS', 'year_level' => 1,
        'academic_status' => 'active', 'admission_year' => 2024], $overrides);
}

function studentContractPrograms(): array
{
    return [
        ['code' => 'BSCS', 'name' => 'Computer Science', 'max_year_level' => 4],
        ['code' => 'BSIT', 'name' => 'Information Technology', 'max_year_level' => 4],
    ];
}
