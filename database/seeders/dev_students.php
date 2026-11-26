<?php
declare(strict_types=1);

// Development-only: fictional program catalog plus one profile for student@example.test
// (run database/seeders/dev_users.php first). Refuses to overwrite existing stores.
// Usage: php database/seeders/dev_students.php

use App\Auth\Repositories\MockUserRepository;
use App\Core\Environment;
use App\Core\JsonStore;
use App\Core\RuntimeDirectory;
use App\Students\Repositories\JsonProgramCatalog;
use App\Students\Repositories\MockStudentRepository;

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
    $runtime = RuntimeDirectory::prepare($config['runtime_path'], dirname(__DIR__, 2) . '/public');
    (new JsonProgramCatalog(new JsonStore($runtime . '/students/programs.json')))->seed([
        ['code' => 'BSCS', 'name' => 'BS Computer Science (fictional)', 'max_year_level' => 4],
        ['code' => 'BSIT', 'name' => 'BS Information Technology (fictional)', 'max_year_level' => 4],
        ['code' => 'BSED', 'name' => 'BS Secondary Education (fictional)', 'max_year_level' => 4],
    ]);
    $account = (new MockUserRepository(new JsonStore($runtime . '/auth/users.json')))->findByEmail('student@example.test');
    if ($account === null) {
        echo 'Programs seeded. No student@example.test account found; skipped the sample profile.' . PHP_EOL;
        exit(0);
    }
    (new MockStudentRepository(new JsonStore($runtime . '/students/students.json')))->create([
        'user_id' => $account->id, 'student_number' => 'DEV-0001', 'first_name' => 'Sample', 'middle_name' => null,
        'last_name' => 'Student', 'program_code' => 'BSCS', 'year_level' => 1, 'academic_status' => 'active',
        'admission_year' => null,
    ], gmdate('Y-m-d\TH:i:s\Z'));
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'Seeding failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
echo 'Seeded 3 fictional programs and a sample profile for student@example.test.' . PHP_EOL;
