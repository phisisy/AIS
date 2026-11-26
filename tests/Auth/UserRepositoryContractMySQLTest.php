<?php
declare(strict_types=1);

use App\Auth\Repositories\MySQLUserRepository;
use Database\Tools\MigrationRunner;

require_once __DIR__ . '/UserRepositoryContract.php';
require_once dirname(__DIR__) . '/Database/MySQLTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/tools/MigrationRunner.php';

if (MySQLTestSupport::enabled()) {
    $factory = static function (): array {
        $pdo = MySQLTestSupport::connect();
        MySQLTestSupport::reset($pdo);
        (new MigrationRunner($pdo, 'ais_test', dirname(__DIR__, 2) . '/database/migrations'))->up();
        $insert = $pdo->prepare('INSERT INTO users (id, email, status, password_hash, auth_version) VALUES (?, ?, ?, ?, ?)');
        $role = $pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (?, ?)');
        foreach (userContractRows() as $row) {
            $insert->execute([$row['id'], $row['email'], $row['status'], $row['password_hash'], $row['auth_version']]);
            foreach ($row['roles'] as $name) {
                $role->execute([$row['id'], $name]);
            }
        }
        return [new MySQLUserRepository(MySQLTestSupport::connectApp()), static fn () => null, $pdo];
    };
    userRepositoryContract('MySQL', $factory);

    test('User repository MySQL: driver failure rolls back audit counter and changes', static function () use ($factory): void {
        [$repo, $cleanup, $owner] = $factory();
        try {
            $insert = $owner->prepare('INSERT INTO audit_logs (id, at, actor_id, target_id, action, outcome, changes) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([1, '2024-01-01 00:00:00', 1, 3, 'user.updated', 'success', '{}']);
            same('User repository unavailable.', throws(RuntimeException::class, static fn () => $repo->transaction(
                static function (\App\Auth\Repositories\UserTransactionInterface $tx): void {
                    $tx->update(3, ['status' => 'inactive']);
                    $tx->appendAudit(1, 3, 'user.updated', ['status' => 'inactive'], '2024-01-01T00:00:01Z');
                }))->getMessage());
            same('active', $repo->findById(3)->status);
            same(0, (int) $owner->query("SELECT last_seq FROM repository_locks WHERE name = 'auth'")->fetchColumn());
            same(1, (int) $owner->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn());
        } finally {
            $cleanup();
        }
    });

    test('User repository MySQL: non-UTC audit timestamp leaves counter unchanged', static function () use ($factory): void {
        [$repo, $cleanup, $owner] = $factory();
        try {
            same('Invalid audit timestamp.', throws(RuntimeException::class, static fn () => $repo->transaction(
                static fn (\App\Auth\Repositories\UserTransactionInterface $tx) => $tx->appendAudit(
                    1, 3, 'user.updated', ['status' => 'inactive'], '2024-01-01 00:00:00')))->getMessage());
            same(0, (int) $owner->query("SELECT last_seq FROM repository_locks WHERE name = 'auth'")->fetchColumn());
            same(0, (int) $owner->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn());
        } finally {
            $cleanup();
        }
    });
}
