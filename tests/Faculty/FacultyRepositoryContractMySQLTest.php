<?php
declare(strict_types=1);

use App\Core\HttpException;
use App\Faculty\Repositories\MySQLFacultyRepository;
use Database\Tools\MigrationRunner;

require_once __DIR__ . '/FacultyRepositoryContract.php';
require_once dirname(__DIR__) . '/Database/MySQLTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/tools/MigrationRunner.php';

// Registered only when the opt-in MySQL test environment is enabled (see MySQLTestSupport).
if (MySQLTestSupport::enabled()) {
    $factory = static function (): array {
        $pdo = MySQLTestSupport::connect();
        MySQLTestSupport::reset($pdo);
        (new MigrationRunner($pdo, 'ais_test', dirname(__DIR__, 2) . '/database/migrations'))->up();
        $users = $pdo->prepare('INSERT INTO users (id, email, status, password_hash, auth_version) VALUES (?, ?, ?, ?, ?)');
        $hash = password_hash('Faculty contract passphrase', PASSWORD_DEFAULT);
        for ($id = 1; $id <= 8; $id++) {
            $users->execute([$id, "faculty{$id}@example.test", 'active', $hash, 1]);
        }
        $history = static function () use ($pdo): array {
            $rows = $pdo->query('SELECT seq, field, from_value, to_value, actor_id FROM faculty_history ORDER BY seq')->fetchAll(PDO::FETCH_ASSOC);
            return array_map(static fn (array $r): array => [(int) $r['seq'], $r['field'], $r['from_value'], $r['to_value'], (int) $r['actor_id']], $rows);
        };
        return [new MySQLFacultyRepository(MySQLTestSupport::connectApp()), static fn () => null, $history, $pdo];
    };
    facultyRepositoryContract('MySQL', $factory);

    test('Faculty repository MySQL: foreign keys reject an unknown account and actor, nothing is written', static function () use ($factory): void {
        [$repo, $cleanup, $history] = $factory();
        try {
            same('Faculty repository unavailable.', throws(RuntimeException::class,
                static fn () => $repo->create(facultyContractFields(99, 'EMP-099'), facultyContractTime(), 2))->getMessage());
            same('Faculty repository unavailable.', throws(RuntimeException::class,
                static fn () => $repo->create(facultyContractFields(1, 'EMP-001'), facultyContractTime(), 99))->getMessage());
            same([0, []], [$repo->search([], 1, 10)['total'], $history()]);
        } finally { $cleanup(); }
    });

    test('Faculty repository MySQL: a failing history insert rolls back the profile update and keeps seq gap-free', static function () use ($factory): void {
        [$repo, $cleanup, $history, $owner] = $factory();
        try {
            $repo->create(facultyContractFields(1, 'EMP-001'), facultyContractTime(), 2);
            $before = $repo->findById(1)->toArray();
            same('Faculty repository unavailable.', throws(RuntimeException::class,
                static fn () => $repo->update(1, ['status' => 'inactive'], '2026-11-21T00:00:00Z', 99))->getMessage());
            same($before, $repo->findById(1)->toArray());
            same(2, count($history()));
            $repo->update(1, ['status' => 'inactive'], '2026-11-21T00:00:00Z', 2);
            same([1, 2, 3], array_column($history(), 0));
            same(3, (int) $owner->query("SELECT last_seq FROM repository_locks WHERE name = 'faculty'")->fetchColumn());
        } finally { $cleanup(); }
    });

    test('Faculty repository MySQL: corrupt rows fail closed', static function () use ($factory): void {
        [$repo, $cleanup, , $owner] = $factory();
        try {
            $repo->create(facultyContractFields(1, 'EMP-001'), facultyContractTime(), 2);
            // Owner-only fault injection: bypass CHECKs for this session, restore before the app reads.
            $owner->exec('SET SESSION check_constraint_checks = OFF');
            try {
                $owner->exec("UPDATE faculty SET employee_number = 'emp-001' WHERE id = 1");
            } finally {
                $owner->exec('SET SESSION check_constraint_checks = ON');
            }
            same('Invalid stored faculty.', throws(RuntimeException::class, static fn () => $repo->findById(1))->getMessage());
        } finally { $cleanup(); }
    });

    test('Faculty repository MySQL: faculty rows are never deleted by the app account', static function () use ($factory): void {
        [, $cleanup, , $owner] = $factory();
        try {
            $app = MySQLTestSupport::connectApp();
            $app->prepare('SELECT 1')->execute();
            $threw = false;
            try {
                $app->exec('DELETE FROM faculty');
            } catch (PDOException) {
                $threw = true;
            }
            check($threw, 'The app account must not hold DELETE on faculty.');
        } finally { $cleanup(); }
    });
}
