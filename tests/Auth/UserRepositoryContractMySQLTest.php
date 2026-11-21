<?php
declare(strict_types=1);

use App\Auth\Repositories\MySQLUserRepository;
use Database\Tools\MigrationRunner;

require_once __DIR__ . '/UserRepositoryContract.php';
require_once dirname(__DIR__) . '/Database/MySQLTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/tools/MigrationRunner.php';

if (MySQLTestSupport::enabled()) {
    userRepositoryContract('MySQL', static function (): array {
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
        return [new MySQLUserRepository($pdo), static fn () => null];
    });
}
