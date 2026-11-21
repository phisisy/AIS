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
        return [new MySQLStudentRepository($pdo), new MySQLProgramCatalog($pdo), static fn () => null];
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
}
