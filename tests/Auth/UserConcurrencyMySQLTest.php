<?php
declare(strict_types=1);

use App\Auth\Repositories\UserAdminRepositoryInterface;

require_once dirname(__DIR__) . '/Database/MySQLConcurrencySupport.php';

if (mysqlConcurrencyEnabled()) {
    test('MySQL A1: concurrent user field updates preserve both changes', static function (): void {
        for ($round = 0; $round < 3; $round++) {
            $owner = mysqlConcurrencyFixture();
            try {
                $results = mysqlRace($owner, 'auth', [
                    ['op' => 'auth_update', 'id' => 3, 'changes' => ['email' => 'changed@example.test'], 'audit' => true],
                    ['op' => 'auth_update', 'id' => 3, 'changes' => ['status' => 'inactive'], 'audit' => true],
                ]);
                same(2, mysqlWinnerCount($results));
                $row = $owner->query('SELECT email, status, auth_version FROM users WHERE id = 3')->fetch(PDO::FETCH_NUM);
                same(['changed@example.test', 'inactive', '2'], array_map('strval', $row));
                same([1, 2], mysqlSequence($owner, 'SELECT id FROM audit_logs ORDER BY id'));
            } finally { $owner = null; }
        }
    });

    test('MySQL A2: competing role sets stay atomic and version bumps match', static function (): void {
        for ($round = 0; $round < 3; $round++) {
            $owner = mysqlConcurrencyFixture();
            try {
                $results = mysqlRace($owner, 'auth', [
                    ['op' => 'auth_update', 'id' => 3, 'changes' => ['roles' => ['faculty']], 'audit' => true],
                    ['op' => 'auth_update', 'id' => 3, 'changes' => ['roles' => ['registrar', 'student']], 'audit' => true],
                ]);
                same(2, mysqlWinnerCount($results));
                $roles = $owner->query('SELECT role FROM user_roles WHERE user_id = 3 ORDER BY role')->fetchAll(PDO::FETCH_COLUMN);
                check($roles === ['faculty'] || $roles === ['registrar', 'student'], 'Mixed role set.');
                same(1, (int) $owner->query('SELECT auth_version FROM users WHERE id = 3')->fetchColumn());
                same([1, 2], mysqlSequence($owner, 'SELECT id FROM audit_logs ORDER BY id'));
            } finally { $owner = null; }
        }
    });

    test('MySQL A3: simultaneous admin demotions preserve one active admin', static function (): void {
        for ($round = 0; $round < 3; $round++) {
            $owner = mysqlConcurrencyFixture();
            try {
                $results = mysqlRace($owner, 'auth', [
                    ['op' => 'auth_update', 'id' => 1, 'changes' => ['roles' => ['faculty']], 'audit' => true],
                    ['op' => 'auth_update', 'id' => 2, 'changes' => ['roles' => ['faculty']], 'audit' => true],
                ]);
                same(1, mysqlWinnerCount($results));
                $loser = array_values(array_filter($results, static fn (array $r): bool => !$r['ok']))[0];
                same([409, UserAdminRepositoryInterface::LAST_ADMIN_MESSAGE], [$loser['status'], $loser['message']]);
                same(1, (int) $owner->query("SELECT COUNT(*) FROM users u JOIN user_roles r ON r.user_id = u.id WHERE u.status = 'active' AND r.role = 'admin'")->fetchColumn());
                same([1], mysqlSequence($owner, 'SELECT id FROM audit_logs ORDER BY id'));
            } finally { $owner = null; }
        }
    });

    test('MySQL A4: audit ids and lock counter include committed writes only', static function (): void {
        for ($round = 0; $round < 3; $round++) {
            $owner = mysqlConcurrencyFixture();
            try {
                $results = mysqlRace($owner, 'auth', [
                    ['op' => 'auth_update', 'id' => 3, 'changes' => ['email' => 'first@example.test'], 'audit' => true],
                    ['op' => 'auth_fail_after_audit', 'id' => 4, 'changes' => ['status' => 'inactive']],
                    ['op' => 'auth_update', 'id' => 5, 'changes' => ['status' => 'inactive'], 'audit' => true],
                ]);
                same(2, mysqlWinnerCount($results));
                same([1, 2], mysqlSequence($owner, 'SELECT id FROM audit_logs ORDER BY id'));
                same(2, (int) $owner->query("SELECT last_seq FROM repository_locks WHERE name = 'auth'")->fetchColumn());
                same(0, (int) $owner->query('SELECT COUNT(*) FROM audit_logs WHERE target_id = 4')->fetchColumn());
                same('active', $owner->query('SELECT status FROM users WHERE id = 4')->fetchColumn());
                same([3, 5], mysqlSequence($owner, 'SELECT target_id FROM audit_logs ORDER BY target_id'));
            } finally { $owner = null; }
        }
    });
}
