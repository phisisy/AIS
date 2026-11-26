<?php
declare(strict_types=1);

use App\Students\Repositories\MySQLProgramCatalog;
use App\Students\Repositories\MySQLStudentRepository;
use Database\Tools\MigrationRunner;

require_once __DIR__ . '/StudentRepositoryContract.php';
require_once dirname(__DIR__) . '/Database/MySQLTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/tools/MigrationRunner.php';

if (MySQLTestSupport::enabled()) {
    $factory = static function (): array {
        $pdo = MySQLTestSupport::connect();
        MySQLTestSupport::reset($pdo);
        (new MigrationRunner($pdo, 'ais_test', dirname(__DIR__, 2) . '/database/migrations'))->up();
        $users = $pdo->prepare('INSERT INTO users (id, email, status, password_hash, auth_version) VALUES (?, ?, ?, ?, ?)');
        $hash = password_hash('Student contract passphrase', PASSWORD_DEFAULT);
        for ($id = 1; $id <= 8; $id++) {
            $users->execute([$id, "student{$id}@example.test", 'active', $hash, 1]);
        }
        $programs = $pdo->prepare('INSERT INTO programs (code, name, max_year_level) VALUES (?, ?, ?)');
        foreach (studentContractPrograms() as $program) {
            $programs->execute([$program['code'], $program['name'], $program['max_year_level']]);
        }
        // Valid fixture data: every user holds a role (the 35-check verifier flags role-less users as user_model/user_roles).
        MySQLTestSupport::completeFixture($pdo, array_fill_keys(range(1, 8), 'student'));
        $app = MySQLTestSupport::connectApp();
        return [new MySQLStudentRepository($app), new MySQLProgramCatalog($app), static fn () => null, $pdo];
    };
    studentRepositoryContract('MySQL', $factory);

    test('Student repository MySQL: foreign keys reject unknown user and program', static function () use ($factory): void {
        [$repo, $catalog, $cleanup] = $factory();
        try {
            same(null, $catalog->find('UNKNOWN'));
            same('Student repository unavailable.', throws(RuntimeException::class,
                static fn () => $repo->create(studentContractFields(99, '2024/099'), studentContractTime()))->getMessage());
            same('Student repository unavailable.', throws(RuntimeException::class,
                static fn () => $repo->create(studentContractFields(1, '2024/001', ['program_code' => 'UNKNOWN']), studentContractTime()))->getMessage());
            same(0, $repo->search([], 1, 10)['total']);
        } finally {
            $cleanup();
        }
    });

    test('Student repository MySQL: history failure rolls back profile update', static function () use ($factory): void {
        [$repo, $catalog, $cleanup, $owner] = $factory();
        try {
            $repo->create(studentContractFields(1, '2024/001'), studentContractTime());
            $before = $repo->findById(1)->toArray();
            // Exhaust the UNSIGNED seq range after creation; the profile UPDATE can
            // succeed, but its subsequent history INSERT cannot fit the next seq.
            $owner->prepare('INSERT INTO student_academic_history (student_id, seq, at, field, from_value, to_value, actor_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([1, 4294967295, '2024-01-01 03:04:05', 'year_level', '1', '1', 0]);
            same('Student repository unavailable.', throws(RuntimeException::class, static fn () => $repo->update(
                1, ['year_level' => 2], '2024-01-02T03:04:05Z'))->getMessage());
            same($before, $repo->findById(1)->toArray());
            same(4, $repo->history(1, 1, 10)['total']);
        } finally {
            $cleanup();
        }
    });

    test('Student repository MySQL: corrupt history fails closed', static function () use ($factory): void {
        [$repo, $catalog, $cleanup, $owner] = $factory();
        try {
            $repo->create(studentContractFields(1, '2024/001'), studentContractTime());
            // Owner-only fault injection: bypass CHECKs for this session, then
            // restore them before the app reads the corrupt row.
            $owner->exec('SET SESSION check_constraint_checks = OFF');
            $corrupt = $owner->prepare('UPDATE student_academic_history SET to_value = ? WHERE student_id = ? AND seq = ?');
            try {
                $corrupt->execute(['not-a-year', 1, 2]);
            } finally {
                $owner->exec('SET SESSION check_constraint_checks = ON');
            }
            same('Invalid student history.', throws(RuntimeException::class,
                static fn () => $repo->history(1, 1, 10))->getMessage());
        } finally {
            $cleanup();
        }
    });
}
