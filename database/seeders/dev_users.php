<?php
declare(strict_types=1);

// Development-only: creates fictional accounts in the mock user store.
// Usage: php database/seeders/dev_users.php
// Passwords are random and printed once. Refuses to touch an existing store.

use App\Auth\Repositories\MockUserRepository;
use App\Auth\Services\AuthService;
use App\Core\Environment;
use App\Core\JsonStore;
use App\Core\RuntimeDirectory;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require dirname(__DIR__, 2) . '/app/autoload.php';

try {
    Environment::load(dirname(__DIR__, 2) . '/.env');
    $config = require dirname(__DIR__, 2) . '/config/app.php';
    if ($config['repository'] !== 'mock') {
        throw new RuntimeException('The development seeder only writes the mock repository.');
    }
    $accounts = [
        [1, 'admin@example.test', ['admin']],
        [2, 'registrar@example.test', ['registrar']],
        [3, 'faculty@example.test', ['faculty']],
        [4, 'student@example.test', ['student']],
    ];
    $rows = [];
    $passwords = [];
    foreach ($accounts as [$id, $email, $roles]) {
        $passwords[$email] = bin2hex(random_bytes(8));
        $rows[] = ['id' => $id, 'email' => $email, 'roles' => $roles, 'status' => 'active',
            'password_hash' => AuthService::hashPassword($passwords[$email]), 'auth_version' => 1];
    }
    $path = RuntimeDirectory::prepare($config['runtime_path'], dirname(__DIR__, 2) . '/public') . '/auth/users.json';
    (new MockUserRepository(new JsonStore($path)))->seed($rows);
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'Seeding failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

echo "Seeded fictional development accounts into {$path}" . PHP_EOL;
echo 'Passwords are shown once; store them in a password manager if needed.' . PHP_EOL;
foreach ($passwords as $email => $password) {
    echo str_pad($email, 26) . $password . PHP_EOL;
}
