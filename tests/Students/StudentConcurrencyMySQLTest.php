<?php
declare(strict_types=1);

use App\Students\Repositories\MySQLStudentRepository;

require_once dirname(__DIR__) . '/Database/MySQLConcurrencySupport.php';

function mysqlRaceStudentFields(int $userId, string $number): array
{
    return ['user_id' => $userId, 'student_number' => $number, 'first_name' => 'First',
        'middle_name' => null, 'last_name' => 'Last', 'program_code' => 'BSCS',
        'year_level' => 1, 'academic_status' => 'active', 'admission_year' => 2024];
}

function mysqlSeedRaceStudent(): void
{
    $app = MySQLTestSupport::connectApp();
    try {
        (new MySQLStudentRepository($app))->create(mysqlRaceStudentFields(3, '2024/003'), '2025-01-01T03:04:05Z');
    } finally { $app = null; }
}

if (mysqlConcurrencyEnabled()) {
    test('MySQL S1: student updates of separate fields are both kept', static function (): void {
        for ($round = 0; $round < 3; $round++) {
            $owner = mysqlConcurrencyFixture();
            try {
                mysqlSeedRaceStudent();
                $results = mysqlRace($owner, 'students', [
                    ['op' => 'student_update', 'id' => 1, 'changes' => ['first_name' => 'Ada']],
                    ['op' => 'student_update', 'id' => 1, 'changes' => ['year_level' => 2]],
                ]);
                same(2, mysqlWinnerCount($results));
                $row = $owner->query('SELECT first_name, year_level FROM students WHERE id = 1')->fetch(PDO::FETCH_NUM);
                same(['Ada', '2'], array_map('strval', $row));
                same([1, 2, 3, 4], mysqlSequence($owner, 'SELECT seq FROM student_academic_history WHERE student_id = 1 ORDER BY seq'));
            } finally { $owner = null; }
        }
    });

    test('MySQL S2: student history has one gap-free entry per tracked change', static function (): void {
        for ($round = 0; $round < 3; $round++) {
            $owner = mysqlConcurrencyFixture();
            try {
                mysqlSeedRaceStudent();
                $results = mysqlRace($owner, 'students', [
                    ['op' => 'student_update', 'id' => 1, 'changes' => ['program_code' => 'BSIT']],
                    ['op' => 'student_update', 'id' => 1, 'changes' => ['year_level' => 2]],
                    ['op' => 'student_update', 'id' => 1, 'changes' => ['academic_status' => 'on_leave']],
                ]);
                same(3, mysqlWinnerCount($results));
                same(range(1, 6), mysqlSequence($owner, 'SELECT seq FROM student_academic_history WHERE student_id = 1 ORDER BY seq'));
                $rows = $owner->query('SELECT field, from_value, to_value, actor_id FROM student_academic_history WHERE student_id = 1 AND seq > 3 ORDER BY seq')->fetchAll(PDO::FETCH_ASSOC);
                $byField = array_column($rows, null, 'field');
                same(['BSCS', 'BSIT', 1], [$byField['program_code']['from_value'], $byField['program_code']['to_value'], (int) $byField['program_code']['actor_id']]);
                same(['1', '2', 1], [$byField['year_level']['from_value'], $byField['year_level']['to_value'], (int) $byField['year_level']['actor_id']]);
                same(['active', 'on_leave', 1], [$byField['academic_status']['from_value'], $byField['academic_status']['to_value'], (int) $byField['academic_status']['actor_id']]);
            } finally { $owner = null; }
        }
    });

    test('MySQL S3: duplicate student number and account races reject losers', static function (): void {
        for ($round = 0; $round < 3; $round++) {
            foreach (['number', 'user'] as $kind) {
                $owner = mysqlConcurrencyFixture();
                try {
                    $jobs = $kind === 'number'
                        ? [mysqlRaceStudentFields(3, '2024/003'), mysqlRaceStudentFields(4, '2024/003'), mysqlRaceStudentFields(5, '2024/003')]
                        : [mysqlRaceStudentFields(3, '2024/003'), mysqlRaceStudentFields(3, '2024/004'), mysqlRaceStudentFields(3, '2024/005')];
                    $results = mysqlRace($owner, 'students', array_map(static fn (array $fields): array => ['op' => 'student_create', 'fields' => $fields], $jobs));
                    same(1, mysqlWinnerCount($results));
                    foreach ($results as $result) {
                        if (!$result['ok']) {
                            same(409, $result['status']);
                            check(isset($result['errors'][$kind === 'number' ? 'student_number' : 'user_id']), 'Missing duplicate field.');
                        }
                    }
                    same(1, (int) $owner->query('SELECT COUNT(*) FROM students')->fetchColumn());
                    same(3, (int) $owner->query('SELECT COUNT(*) FROM student_academic_history')->fetchColumn());
                } finally { $owner = null; }
            }
        }
    });

    test('MySQL S4: thrown check leaves no partial student row or history', static function (): void {
        for ($round = 0; $round < 3; $round++) {
            $owner = mysqlConcurrencyFixture();
            try {
                mysqlSeedRaceStudent();
                $results = mysqlRace($owner, 'students', [
                    ['op' => 'student_check_fail', 'id' => 1, 'changes' => ['program_code' => 'BSIT']],
                    ['op' => 'student_update', 'id' => 1, 'changes' => ['year_level' => 2]],
                ]);
                same(1, mysqlWinnerCount($results));
                same('Injected failure.', array_values(array_filter($results, static fn (array $r): bool => !$r['ok']))[0]['message']);
                $row = $owner->query('SELECT program_code, year_level FROM students WHERE id = 1')->fetch(PDO::FETCH_NUM);
                same(['BSCS', '2'], array_map('strval', $row));
                same([1, 2, 3, 4], mysqlSequence($owner, 'SELECT seq FROM student_academic_history WHERE student_id = 1 ORDER BY seq'));
                same(['year_level'], $owner->query('SELECT field FROM student_academic_history WHERE student_id = 1 AND seq = 4')->fetchAll(PDO::FETCH_COLUMN));
            } finally { $owner = null; }
        }
    });
}
