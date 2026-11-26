<?php
declare(strict_types=1);

use App\Core\HttpException;
use App\Faculty\Repositories\FacultyRepositoryInterface;

function facultyContractTime(): string
{
    return '2026-11-20T01:02:03Z';
}

function facultyContractFields(int $userId, string $number, array $override = []): array
{
    return array_replace(['user_id' => $userId, 'employee_number' => $number, 'first_name' => 'Ana', 'last_name' => 'Reyes',
        'department' => 'Computer Science', 'status' => 'active'], $override);
}

/**
 * Registers the same cases against a fresh mock or MySQL fixture.
 * $factory returns [repository, cleanup, history reader: fn(): list<array{seq,field,from,to,actor_id}>].
 */
function facultyRepositoryContract(string $backend, callable $factory): void
{
    $case = static function (string $name, callable $body) use ($backend, $factory): void {
        test("Faculty repository {$backend}: {$name}", static function () use ($factory, $body): void {
            [$repository, $cleanup, $history] = $factory();
            try {
                $body($repository, $history);
            } finally {
                $cleanup();
            }
        });
    };

    $case('missing lookups and empty search', static function (FacultyRepositoryInterface $repo): void {
        same([null, null, [], 0], [$repo->findById(1), $repo->findByUserId(1), $repo->findByIds([1, 2]), $repo->search([], 1, 10)['total']]);
    });

    $case('create, typed row and creation history', static function (FacultyRepositoryInterface $repo, callable $history): void {
        $created = $repo->create(facultyContractFields(1, 'EMP-001'), facultyContractTime(), 2);
        same(1, $created->id());
        same($created->toArray(), $repo->findById(1)->toArray());
        same(1, $repo->findByUserId(1)->id());
        same(['id', 'user_id', 'employee_number', 'first_name', 'last_name', 'department', 'status', 'created_at', 'updated_at'], array_keys($created->toArray()));
        same([1, 1, 'EMP-001', 'active', facultyContractTime(), facultyContractTime()],
            [$created->id(), $created->userId(), $created->employeeNumber(), $created->status(), $created->toArray()['created_at'], $created->toArray()['updated_at']]);
        same('Reyes, Ana', $created->displayName());
        same([[1, 'status', null, 'active', 2], [2, 'department', null, 'Computer Science', 2]], $history());
        same(2, $repo->create(facultyContractFields(2, 'EMP-002'), facultyContractTime(), 2)->id());
    });

    $case('duplicate errors are 409 and can be combined; nothing is written', static function (FacultyRepositoryInterface $repo, callable $history): void {
        $repo->create(facultyContractFields(1, 'EMP-001'), facultyContractTime(), 2);
        $error = throws(HttpException::class, static fn () => $repo->create(facultyContractFields(1, 'EMP-001'), facultyContractTime(), 2));
        same([409, ['user_id' => 'This account already has a faculty profile.', 'employee_number' => 'This employee number is already in use.']],
            [$error->status, $error->errors]);
        $error = throws(HttpException::class, static fn () => $repo->create(facultyContractFields(2, 'EMP-001'), facultyContractTime(), 2));
        same(['employee_number' => 'This employee number is already in use.'], $error->errors);
        $error = throws(HttpException::class, static fn () => $repo->create(facultyContractFields(1, 'EMP-002'), facultyContractTime(), 2));
        same(['user_id' => 'This account already has a faculty profile.'], $error->errors);
        same(1, $repo->search([], 1, 10)['total']);
        same(2, count($history()));
    });

    $case('update appends one entry per changed tracked field, none for names or no-ops', static function (FacultyRepositoryInterface $repo, callable $history): void {
        $repo->create(facultyContractFields(1, 'EMP-001'), facultyContractTime(), 2);
        $later = '2026-11-21T00:00:00Z';
        $row = $repo->update(1, ['status' => 'inactive', 'department' => 'Math'], $later, 3);
        same(['inactive', 'Math', $later, facultyContractTime()], [$row->status(), $row->department(), $row->toArray()['updated_at'], $row->toArray()['created_at']]);
        same([[3, 'status', 'active', 'inactive', 3], [4, 'department', 'Computer Science', 'Math', 3]], array_slice($history(), 2));
        $repo->update(1, ['first_name' => 'Anna', 'last_name' => 'Reyes-Cruz'], $later, 3);
        $repo->update(1, ['status' => 'inactive'], '2026-11-22T00:00:00Z', 3);
        same(4, count($history()));
        same('2026-11-22T00:00:00Z', $repo->findById(1)->toArray()['updated_at']);
        same('Reyes-Cruz, Anna', $repo->findById(1)->displayName());
        same(404, throws(HttpException::class, static fn () => $repo->update(9, ['status' => 'inactive'], $later, 3))->status);
        foreach ([[], ['user_id' => 2], ['employee_number' => 'EMP-009'], ['id' => 5], ['created_at' => $later]] as $bad) {
            throws(InvalidArgumentException::class, static fn () => $repo->update(1, $bad, $later, 3));
        }
        same(4, count($history()));
    });

    $case('findByIds, search filters, order and pagination', static function (FacultyRepositoryInterface $repo): void {
        $repo->create(facultyContractFields(1, 'EMP-001', ['first_name' => 'Ana', 'last_name' => 'Reyes']), facultyContractTime(), 2);
        $repo->create(facultyContractFields(2, 'FAC-100', ['first_name' => 'Ben', 'last_name' => 'Cruz', 'status' => 'inactive']), facultyContractTime(), 2);
        $repo->create(facultyContractFields(3, 'EMP-003', ['first_name' => '100%', 'last_name' => 'Under_score']), facultyContractTime(), 2);
        same([1, 3], array_keys($repo->findByIds([3, 1, 99])));
        $ids = static fn (array $filters, int $page = 1, int $per = 10): array
            => array_map(static fn ($f): int => $f->id(), $repo->search($filters, $page, $per)['faculty']);
        same([1, 2, 3], $ids([]));
        same([2], $ids(['search' => 'fac-100']));
        same([1], $ids(['search' => 'ANA']));
        same([3], $ids(['search' => '100%']));
        same([3], $ids(['search' => 'er_sc']));
        // wildcards in the search text are literal characters, not patterns
        same([3], $ids(['search' => '%']));
        same([], $ids(['search' => 'a_s']));
        same([2], $ids(['status' => 'inactive']));
        same([1, 3], $ids(['status' => 'active']));
        same([2], $ids([], 2, 1));
        same(3, $repo->search([], 1, 1)['total']);
    });
}
