<?php
declare(strict_types=1);

use App\Auth\Repositories\UserAdminRepositoryInterface;
use App\Auth\Repositories\UserTransactionInterface;
use App\Core\HttpException;

require_once __DIR__ . '/AdminSupport.php';

function adminCase(callable $body): void
{
    $directory = tempDirectory();
    try {
        $body($directory, ...adminFixture($directory));
    } finally {
        removeTestDirectory($directory);
    }
}

function assertNoSecretsInAudit(array $entries): void
{
    $json = json_encode($entries, JSON_THROW_ON_ERROR);
    foreach (['$2y$', 'password_hash', 'Synthetic-test-pass-42', 'New-passphrase', 'csrf', 'AIS_SESSION'] as $secret) {
        check(!str_contains($json, $secret), "Audit log contains secret material: {$secret}");
    }
}

test('Admin create: normalizes input, defaults to active, audits, never returns hashes', function (): void {
    adminCase(function (string $directory, $service, $repository): void {
        $admin = $repository->findById(1);
        $created = $service->create($admin, ['email' => '  New.User@Example.TEST ', 'password' => 'New-passphrase-123',
            'roles' => ['registrar', 'faculty', 'faculty']]);
        same(['id' => 6, 'email' => 'new.user@example.test', 'roles' => ['faculty', 'registrar'], 'status' => 'active'], $created);
        check($repository->findById(6)->verifyPassword('New-passphrase-123'));
        $audit = $repository->auditEntries();
        same(1, count($audit));
        same(['id' => 1, 'at' => '2023-11-14T22:13:20Z', 'actor_id' => 1, 'target_id' => 6, 'action' => 'user.created',
            'outcome' => 'success', 'changes' => ['email' => 'new.user@example.test', 'roles' => ['faculty', 'registrar'], 'status' => 'active']], $audit[0]);
        assertNoSecretsInAudit($audit);
        same('inactive', $service->create($admin, ['email' => 'x@example.test', 'password' => 'New-passphrase-123',
            'roles' => ['student'], 'status' => 'inactive'])['status']);
    });
});

test('Admin create: duplicate email (any case) is 409 and validation errors are 422 per field', function (): void {
    adminCase(function (string $directory, $service, $repository): void {
        $admin = $repository->findById(1);
        $error = throws(HttpException::class, fn () => $service->create($admin,
            ['email' => ' STUDENT@example.test', 'password' => 'New-passphrase-123', 'roles' => ['student']]));
        same([409, ['email' => 'This email is already in use.']], [$error->status, $error->errors]);
        $invalid = [
            'missing fields' => [[], ['email', 'password', 'roles']],
            'bad email' => [['email' => 'not-an-email', 'password' => 'New-passphrase-123', 'roles' => ['student']], ['email']],
            'email type' => [['email' => ['a@b.test'], 'password' => 'New-passphrase-123', 'roles' => ['student']], ['email']],
            'short password' => [['email' => 'a@example.test', 'password' => 'short', 'roles' => ['student']], ['password']],
            'long password' => [['email' => 'a@example.test', 'password' => str_repeat('a', 73), 'roles' => ['student']], ['password']],
            'blank password' => [['email' => 'a@example.test', 'password' => str_repeat(' ', 14), 'roles' => ['student']], ['password']],
            'nul password' => [['email' => 'a@example.test', 'password' => "New-passphrase\0x", 'roles' => ['student']], ['password']],
            'no roles' => [['email' => 'a@example.test', 'password' => 'New-passphrase-123', 'roles' => []], ['roles']],
            'unknown role' => [['email' => 'a@example.test', 'password' => 'New-passphrase-123', 'roles' => ['superuser']], ['roles']],
            'cased role' => [['email' => 'a@example.test', 'password' => 'New-passphrase-123', 'roles' => ['Admin']], ['roles']],
            'roles map' => [['email' => 'a@example.test', 'password' => 'New-passphrase-123', 'roles' => ['x' => 'admin']], ['roles']],
            'bad status' => [['email' => 'a@example.test', 'password' => 'New-passphrase-123', 'roles' => ['student'], 'status' => 'banned'], ['status']],
        ];
        foreach ($invalid as $name => [$input, $fields]) {
            $error = throws(HttpException::class, fn () => $service->create($admin, $input));
            check($error->status === 422, "{$name}: expected 422, got {$error->status}");
            same($fields, array_keys($error->errors));
        }
        // Only the duplicate (a refusal) is audited; 422 validation errors are not.
        same(['user.created:denied:duplicate_email'], auditSummary($repository));
        same(5, $repository->search('', 1, 100)['total']);
    });
});

test('Admin update: role assignment, removal, multi-role, email change; no-op writes no audit', function (): void {
    adminCase(function (string $directory, $service, $repository): void {
        $admin = $repository->findById(1);
        same(['faculty', 'registrar'], $service->update($admin, 4, ['roles' => ['registrar', 'faculty']])['roles']);
        same(['registrar'], $service->update($admin, 4, ['roles' => ['registrar']])['roles']);
        same('fac2@example.test', $service->update($admin, 4, ['email' => 'FAC2@example.test'])['email']);
        $service->update($admin, 4, ['roles' => ['registrar'], 'email' => 'fac2@example.test']);
        $audit = $repository->auditEntries();
        same(3, count($audit));
        same(['roles' => ['from' => ['faculty'], 'to' => ['faculty', 'registrar']]], $audit[0]['changes']);
        same(['roles' => ['from' => ['faculty', 'registrar'], 'to' => ['registrar']]], $audit[1]['changes']);
        same(['email' => ['from' => 'faculty@example.test', 'to' => 'fac2@example.test']], $audit[2]['changes']);
        same('user.updated', $audit[2]['action']);
        // Granting admin to another user is allowed; it is an explicit, audited change.
        same(['admin', 'student'], $service->update($admin, 3, ['roles' => ['student', 'admin']])['roles']);
        same(409, throws(HttpException::class, fn () => $service->update($admin, 3, ['email' => 'admin2@example.test']))->status);
        same(422, throws(HttpException::class, fn () => $service->update($admin, 3, []))->status);
        same(422, throws(HttpException::class, fn () => $service->update($admin, 3, ['roles' => []]))->status);
        same(404, throws(HttpException::class, fn () => $service->update($admin, 99, ['roles' => ['student']]))->status);
    });
});

test('Admin cannot change own roles, status or password through admin endpoints', function (): void {
    adminCase(function (string $directory, $service, $repository): void {
        $admin = $repository->findById(1);
        foreach ([
            fn () => $service->update($admin, 1, ['roles' => ['admin', 'registrar']]),
            fn () => $service->update($admin, 1, ['roles' => ['registrar']]),
            fn () => $service->changeStatus($admin, 1, ['status' => 'inactive']),
            fn () => $service->resetPassword($admin, 1, ['password' => 'New-passphrase-123', 'current_password' => 'Synthetic-test-pass-42'], '192.0.2.1'),
        ] as $attempt) {
            same(409, throws(HttpException::class, $attempt)->status);
        }
        // Updating only one's own email is allowed.
        same('me@example.test', $service->update($admin, 1, ['email' => 'me@example.test'])['email']);
        same(['admin'], $repository->findById(1)->roles);
        same('active', $repository->findById(1)->status);
    });
});

test('Admin activation and deactivation are audited; repeating a status is a no-op', function (): void {
    adminCase(function (string $directory, $service, $repository): void {
        $admin = $repository->findById(1);
        same('inactive', $service->changeStatus($admin, 3, ['status' => 'inactive'])['status']);
        same('inactive', $service->changeStatus($admin, 3, ['status' => 'inactive'])['status']);
        same('active', $service->changeStatus($admin, 3, ['status' => 'active'])['status']);
        same(['user.deactivated', 'user.activated'], array_column($repository->auditEntries(), 'action'));
        same(422, throws(HttpException::class, fn () => $service->changeStatus($admin, 3, ['status' => 'deleted']))->status);
        same(422, throws(HttpException::class, fn () => $service->changeStatus($admin, 3, []))->status);
    });
});

test('Admin password reset: re-confirms the admin, bumps the target auth_version, audits no secrets', function (): void {
    adminCase(function (string $directory, $service, $repository): void {
        $admin = $repository->findById(1);
        $error = throws(HttpException::class, fn () => $service->resetPassword($admin, 3,
            ['password' => 'New-passphrase-123', 'current_password' => 'wrong'], '192.0.2.1'));
        same([403, 'Current password confirmation failed.'], [$error->status, $error->getMessage()]);
        same(422, throws(HttpException::class, fn () => $service->resetPassword($admin, 3,
            ['password' => 'short', 'current_password' => 'Synthetic-test-pass-42'], '192.0.2.1'))->status);
        same(1, $repository->findById(3)->authVersion);
        $service->resetPassword($admin, 3, ['password' => 'New-passphrase-123', 'current_password' => 'Synthetic-test-pass-42'], '192.0.2.1');
        $target = $repository->findById(3);
        same(2, $target->authVersion);
        check($target->verifyPassword('New-passphrase-123') && !$target->verifyPassword('Synthetic-test-pass-42'));
        same(['user.password_reset:denied:confirmation_failed', 'user.password_reset:success'], auditSummary($repository));
        same(['signed_out_everywhere' => true], $repository->auditEntries()[1]['changes']);
        assertNoSecretsInAudit($repository->auditEntries());
    });
});

test('Admin password confirmation is rate limited', function (): void {
    adminCase(function (string $directory, $service, $repository): void {
        $admin = $repository->findById(1);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            same(403, throws(HttpException::class, fn () => $service->resetPassword($admin, 3,
                ['password' => 'New-passphrase-123', 'current_password' => 'wrong'], '192.0.2.1'))->status);
        }
        same(429, throws(HttpException::class, fn () => $service->resetPassword($admin, 3,
            ['password' => 'New-passphrase-123', 'current_password' => 'Synthetic-test-pass-42'], '192.0.2.1'))->status);
    });
});

test('Admin operations reject non-admins (403) and inactive or stale actors (401/403) with no change', function (): void {
    adminCase(function (string $directory, $service, $repository, $store): void {
        $student = $repository->findById(3);
        same(403, throws(HttpException::class, fn () => $service->list($student, []))->status);
        same(403, throws(HttpException::class, fn () => $service->update($student, 3, ['roles' => ['admin']]))->status);
        same(401, throws(HttpException::class, fn () => $service->list($repository->findById(5), []))->status);
        // Actor object loaded before the change, as the guard would have; the change happens before the lock.
        $staleAdmin = $repository->findById(2);
        $store->transaction(static function (array &$data): void { $data['users'][1]['roles'] = ['faculty']; });
        same(403, throws(HttpException::class, fn () => $service->changeStatus($staleAdmin, 3, ['status' => 'inactive']))->status);
        $staleAdmin1 = $repository->findById(1);
        $store->transaction(static function (array &$data): void { $data['users'][1]['roles'] = ['admin']; $data['users'][0]['status'] = 'inactive'; });
        same(401, throws(HttpException::class, fn () => $service->create($staleAdmin1,
            ['email' => 'z@example.test', 'password' => 'New-passphrase-123', 'roles' => ['admin']]))->status);
        same('active', $repository->findById(3)->status);
        same(null, $repository->findByEmail('z@example.test'));
        same(['user.updated:denied:actor_not_admin', 'user.deactivated:denied:actor_not_admin',
            'user.created:denied:actor_not_signed_in_or_inactive'], auditSummary($repository));
        $entry = $repository->auditEntries()[0];
        same([3, 3, ['reason' => 'actor_not_admin']], [$entry['actor_id'], $entry['target_id'], $entry['changes']]);
    });
});

test('Admin whose password was reset after the guard check cannot complete the request (auth_version re-check)', function (): void {
    adminCase(function (string $directory, $service, $repository): void {
        $inFlight = $repository->findById(2);
        // Admin-to-admin resets are blocked by the API, so simulate the password change at the repository
        // (as a future account-recovery process would do).
        $repository->transaction(static fn (UserTransactionInterface $tx) => $tx->update(2, ['password_hash' => App\Auth\Services\AuthService::hashPassword('Recovered-passphrase-1')]));
        same(2, $repository->findById(2)->authVersion);
        same(401, throws(HttpException::class, fn () => $service->changeStatus($inFlight, 3, ['status' => 'inactive']))->status);
        same('active', $repository->findById(3)->status);
    });
});

test('Deactivation bumps auth_version so reactivation cannot revive old sessions', function (): void {
    adminCase(function (string $directory, $service, $repository): void {
        $admin = $repository->findById(1);
        $service->changeStatus($admin, 3, ['status' => 'inactive']);
        same(2, $repository->findById(3)->authVersion);
        $service->changeStatus($admin, 3, ['status' => 'active']);
        same(2, $repository->findById(3)->authVersion);
    });
});

test('Audit entries accept only allowlisted change keys and a known outcome', function (): void {
    adminCase(function (string $directory, $service, $repository): void {
        $before = file_get_contents($directory . '/users.json');
        foreach ([[['note' => 'free text'], 'success'], [['email' => 'x'], 'maybe'], [['reason' => ['token' => 'x']], 'denied']] as [$changes, $outcome]) {
            throws(RuntimeException::class, fn () => $repository->transaction(
                static fn (UserTransactionInterface $tx) => $tx->appendAudit(1, 3, 'user.updated', $changes, 'now', $outcome)));
        }
        same($before, file_get_contents($directory . '/users.json'));
    });
});

test('Repository refuses any change that leaves no active admin, and leaves the store untouched', function (): void {
    adminCase(function (string $directory, $service, $repository): void {
        $service->changeStatus($repository->findById(1), 2, ['status' => 'inactive']);
        same(1, activeAdminCount($repository));
        $snapshot = file_get_contents($directory . '/users.json');
        foreach ([['status' => 'inactive'], ['roles' => ['faculty']]] as $change) {
            $error = throws(HttpException::class, fn () => $repository->transaction(
                static fn (UserTransactionInterface $tx) => $tx->update(1, $change)));
            same([409, UserAdminRepositoryInterface::LAST_ADMIN_MESSAGE], [$error->status, $error->getMessage()]);
        }
        same($snapshot, file_get_contents($directory . '/users.json'));
        // Re-activating another admin first makes the same change legal.
        $service->changeStatus($repository->findById(1), 5, ['status' => 'active']);
        $repository->transaction(static fn (UserTransactionInterface $tx) => $tx->update(1, ['roles' => ['faculty']]));
        same(1, activeAdminCount($repository));
    });
});

test('Admin change and its audit entry are atomic: audit failure rolls the change back', function (): void {
    $directory = tempDirectory();
    try {
        $clockCalls = 0;
        [$service, $repository] = adminFixture($directory, null, static function () use (&$clockCalls): int {
            if (++$clockCalls > 0) {
                throw new RuntimeException('audit clock failure');
            }
            return 0;
        });
        $snapshot = file_get_contents($directory . '/users.json');
        throws(RuntimeException::class, fn () => $service->changeStatus($repository->findById(1), 3, ['status' => 'inactive']));
        throws(RuntimeException::class, fn () => $service->create($repository->findById(1),
            ['email' => 'n@example.test', 'password' => 'New-passphrase-123', 'roles' => ['student']]));
        same($snapshot, file_get_contents($directory . '/users.json'));
        // A secret-looking audit key is refused and also rolls back.
        throws(RuntimeException::class, fn () => $repository->transaction(static function (UserTransactionInterface $tx): void {
            $tx->update(3, ['status' => 'inactive']);
            $tx->appendAudit(1, 3, 'user.updated', ['nested' => ['password_hash' => 'x']], 'now');
        }));
        same($snapshot, file_get_contents($directory . '/users.json'));
    } finally {
        removeTestDirectory($directory);
    }
});

test('Admin change is not saved when the store file cannot be written', function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        return; // Uses Windows open-file replacement semantics (see StorageTest).
    }
    $directory = tempDirectory();
    $holder = null;
    try {
        [$service, $repository] = adminFixture($directory);
        $admin = $repository->findById(1);
        $holder = holdFileOpen($directory . '/users.json', 4000, $directory . '/ready');
        same('Unable to replace store.', throws(RuntimeException::class,
            fn () => $service->changeStatus($admin, 3, ['status' => 'inactive']))->getMessage());
        proc_close($holder);
        $holder = null;
        same('active', $repository->findById(3)->status);
        same([], $repository->auditEntries());
    } finally {
        if (is_resource($holder)) {
            proc_close($holder);
        }
        removeTestDirectory($directory);
    }
});

test('Admin list searches by email with bounded pagination', function (): void {
    adminCase(function (string $directory, $service, $repository): void {
        $admin = $repository->findById(1);
        $all = $service->list($admin, []);
        same(['page' => 1, 'per_page' => 20, 'total' => 5], $all['pagination']);
        same([1, 2, 3, 4, 5], array_column($all['users'], 'id'));
        same([1, 2, 5], array_column($service->list($admin, ['search' => 'ADMIN'])['users'], 'id'));
        $page = $service->list($admin, ['page' => '2', 'per_page' => '2']);
        same([3, 4], array_column($page['users'], 'id'));
        same([], $service->list($admin, ['page' => '9'])['users']);
        check(!str_contains(json_encode($all), 'password'), 'List must not expose password data.');
        foreach ([['page' => '0'], ['per_page' => '101'], ['page' => 'x'], ['search' => ['a']], ['search' => str_repeat('a', 255)]] as $query) {
            same(422, throws(HttpException::class, fn () => $service->list($admin, $query))->status);
        }
        same(404, throws(HttpException::class, fn () => $service->get($admin, 99))->status);
        same('student@example.test', $service->get($admin, 3)['email']);
    });
});

test('Admin cannot reset another administrator password (active or inactive); non-admin resets still work', function (): void {
    adminCase(function (string $directory, $service, $repository): void {
        $admin = $repository->findById(1);
        $confirm = ['password' => 'New-passphrase-123', 'current_password' => 'Synthetic-test-pass-42'];
        foreach ([2, 5] as $adminTarget) {
            $error = throws(HttpException::class, fn () => $service->resetPassword($admin, $adminTarget, $confirm, '192.0.2.1'));
            same([409, App\Auth\Services\UserService::ADMIN_TARGET_MESSAGE, []], [$error->status, $error->getMessage(), $error->errors]);
            $target = $repository->findById($adminTarget);
            same(1, $target->authVersion);
            check($target->verifyPassword('Synthetic-test-pass-42'), 'Admin password must be unchanged.');
        }
        // A multi-role account holding admin is still an administrator.
        $service->update($admin, 4, ['roles' => ['faculty', 'admin']]);
        same(409, throws(HttpException::class, fn () => $service->resetPassword($admin, 4, $confirm, '192.0.2.1'))->status);
        // Re-confirmation still applies first: a wrong password is a confirmation failure, not a target error.
        same(403, throws(HttpException::class, fn () => $service->resetPassword($admin, 2,
            ['password' => 'New-passphrase-123', 'current_password' => 'wrong'], '192.0.2.1'))->status);
        $service->resetPassword($admin, 3, $confirm, '192.0.2.1');
        same(2, $repository->findById(3)->authVersion);
        same(['user.password_reset:denied:target_is_admin', 'user.password_reset:denied:target_is_admin',
            'user.updated:success', 'user.password_reset:denied:target_is_admin',
            'user.password_reset:denied:confirmation_failed', 'user.password_reset:success'], auditSummary($repository));
    });
});

test('Audit logs (T-05): admin only, newest first, filters, pagination, strict validation', function (): void {
    adminCase(function (string $directory, $service, $repository): void {
        $admin = $repository->findById(1);
        $repository->transaction(function ($tx): void {
            $tx->appendAudit(1, 3, 'user.updated', ['status' => 'x'], '2024-01-01T00:00:00Z');
            $tx->appendAudit(1, 0, 'user.created', ['reason' => 'actor_not_admin'], '2024-01-02T00:00:00Z', 'denied');
            $tx->appendAudit(2, 3, 'user.updated', ['status' => 'y'], 'now'); // non-ISO fixture
        });
        foreach ([3, 4] as $id) {
            same(403, throws(HttpException::class, fn () => $service->auditLogs($repository->findById($id), []))->status);
        }
        $all = $service->auditLogs($admin, []);
        same([3, 2, 1], array_column($all['entries'], 'id'));
        same(['page' => 1, 'per_page' => 20, 'total' => 3], $all['pagination']);
        same([2], array_column($service->auditLogs($admin, ['page' => '2', 'per_page' => '1'])['entries'], 'id'));
        same([2], array_column($service->auditLogs($admin, ['outcome' => 'denied'])['entries'], 'id'));
        same([3], array_column($service->auditLogs($admin, ['actor_id' => '2', 'target_id' => '3'])['entries'], 'id'));
        // Mock fails closed: the non-ISO 'now' entry never matches a date filter.
        same([2, 1], array_column($service->auditLogs($admin, ['from' => '2023-01-01T00:00:00Z'])['entries'], 'id'));
        same([1], array_column($service->auditLogs($admin, ['from' => '2024-01-01T00:00:00Z', 'to' => '2024-01-01T23:59:59Z'])['entries'], 'id'));
        $bad = ['page' => '0', 'per_page' => '101', 'action' => 'Bad Action', 'outcome' => 'maybe', 'actor_id' => '-1',
            'target_id' => '1.5', 'from' => '2024-02-30T00:00:00Z', 'to' => '2024-01-01 00:00:00'];
        foreach ($bad as $key => $value) {
            same([$key], array_keys(throws(HttpException::class, fn () => $service->auditLogs($admin, [$key => $value]))->errors));
            same(422, throws(HttpException::class, fn () => $service->auditLogs($admin, [$key => [$value]]))->status);
        }
        same(422, throws(HttpException::class, fn () => $service->auditLogs($admin,
            ['from' => '2024-01-02T00:00:00Z', 'to' => '2024-01-01T00:00:00Z']))->status);
        same(422, throws(HttpException::class, fn () => $service->auditLogs($admin, ['action' => '']))->status);
        // L6: unknown query keys are refused (422, errors[<key>]), alone or beside valid ones.
        foreach (['search', 'id', 'PAGE', 'user_id'] as $key) {
            same([$key], array_keys(throws(HttpException::class, fn () => $service->auditLogs($admin, [$key => '1']))->errors));
        }
        same(['bogus'], array_keys(throws(HttpException::class, fn () => $service->auditLogs($admin, ['outcome' => 'denied', 'bogus' => 'x']))->errors));
        same(3, count($repository->auditEntries()));
    });
});
