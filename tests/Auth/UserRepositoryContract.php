<?php
declare(strict_types=1);

use App\Auth\Repositories\UserAdminRepositoryInterface;
use App\Auth\Repositories\UserTransactionInterface;
use App\Core\HttpException;

/** Register the same cases against either backend. Each factory call starts a clean store. */
function userRepositoryContract(string $backend, callable $factory): void
{
    $case = static function (string $name, callable $body) use ($backend, $factory): void {
        test("User repository {$backend}: {$name}", static function () use ($factory, $body): void {
            [$repository, $cleanup] = $factory();
            try {
                $body($repository);
            } finally {
                $cleanup();
            }
        });
    };

    $case('lookups and email normalization', static function (UserAdminRepositoryInterface $repo): void {
        same(null, $repo->findById(999));
        same(null, $repo->findByEmail('missing@example.test'));
        same('admin@example.test', $repo->findById(1)->email);
        same(1, $repo->findByEmail('  ADMIN@EXAMPLE.TEST ')->id);
        same(['admin'], $repo->findById(1)->roles);
        check($repo->findById(1)->verifyPassword('Contract-passphrase-42'));
    });

    $case('search order, pages, and literal wildcards', static function (UserAdminRepositoryInterface $repo): void {
        $repo->transaction(static fn (UserTransactionInterface $tx) => $tx->insert('percent%_!@example.test', ['student'], 'active', userContractHash()));
        $all = $repo->search('', 1, 2);
        same(4, $all['total']);
        same([1, 2], array_map(static fn ($user): int => $user->id, $all['users']));
        same([3, 4], array_map(static fn ($user): int => $user->id, $repo->search('', 2, 2)['users']));
        same([], $repo->search('', 9, 2)['users']);
        same(2, $repo->search(' ADMIN ', 1, 10)['total']);
        same(1, $repo->search('%_!', 1, 10)['total']);
        same(1, $repo->search('%', 1, 10)['total']);
        same(1, $repo->search('_', 1, 10)['total']);
        same(0, $repo->search('no match', 1, 10)['total']);
    });

    $case('insert, update, roles, and version bumps', static function (UserAdminRepositoryInterface $repo): void {
        $created = $repo->transaction(static fn (UserTransactionInterface $tx) => $tx->insert(' New@Example.TEST ', ['faculty', 'registrar'], 'active', userContractHash()));
        same([4, 'new@example.test', ['faculty', 'registrar'], 'active', 1],
            [$created->id, $created->email, $created->roles, $created->status, $created->authVersion]);
        $repo->transaction(static function (UserTransactionInterface $tx): void {
            same(4, $tx->findByEmail(' NEW@EXAMPLE.TEST ')->id);
            same(null, $tx->find(999));
            same(null, $tx->findByEmail('absent@example.test'));
            $tx->update(4, ['roles' => ['student'], 'email' => ' Revised@Example.TEST ']);
            same('revised@example.test', $tx->find(4)->email);
            $tx->update(4, ['status' => 'inactive']);
            $tx->update(4, ['status' => 'active']);
            $tx->update(4, ['password_hash' => userContractHash()]);
        });
        $user = $repo->findById(4);
        same(['student'], $user->roles);
        same(3, $user->authVersion);
        same(null, $repo->findByEmail('new@example.test'));
        same(4, $repo->findByEmail('REVISED@example.test')->id);
    });

    $case('duplicate, unsupported, and missing updates', static function (UserAdminRepositoryInterface $repo): void {
        $duplicate = throws(HttpException::class, static fn () => $repo->transaction(
            static fn (UserTransactionInterface $tx) => $tx->insert(' ADMIN@EXAMPLE.TEST ', ['student'], 'active', userContractHash())));
        same([409, 'Validation failed.', ['email' => 'This email is already in use.']],
            [$duplicate->status, $duplicate->getMessage(), $duplicate->errors]);
        $duplicate = throws(HttpException::class, static fn () => $repo->transaction(
            static fn (UserTransactionInterface $tx) => $tx->update(3, ['email' => 'admin@example.test'])));
        same(409, $duplicate->status);
        same('Unsupported user change.', throws(InvalidArgumentException::class, static fn () => $repo->transaction(
            static fn (UserTransactionInterface $tx) => $tx->update(3, ['id' => 8])))->getMessage());
        $missing = throws(HttpException::class, static fn () => $repo->transaction(
            static fn (UserTransactionInterface $tx) => $tx->update(999, ['status' => 'inactive'])));
        same([404, 'User not found.'], [$missing->status, $missing->getMessage()]);
        same('student@example.test', $repo->findById(3)->email);
    });

    $case('last active admin rule', static function (UserAdminRepositoryInterface $repo): void {
        $repo->transaction(static fn (UserTransactionInterface $tx) => $tx->update(2, ['status' => 'inactive']));
        foreach ([['status' => 'inactive'], ['roles' => ['faculty']]] as $change) {
            $error = throws(HttpException::class, static fn () => $repo->transaction(
                static fn (UserTransactionInterface $tx) => $tx->update(1, $change)));
            same([409, UserAdminRepositoryInterface::LAST_ADMIN_MESSAGE], [$error->status, $error->getMessage()]);
        }
        same('active', $repo->findById(1)->status);
        same(['admin'], $repo->findById(1)->roles);
        $repo->transaction(static fn (UserTransactionInterface $tx) => $tx->update(2, ['status' => 'active']));
        $repo->transaction(static fn (UserTransactionInterface $tx) => $tx->update(1, ['roles' => ['faculty']]));
        same(['faculty'], $repo->findById(1)->roles);
    });

    $case('audit shape, ordering, and secret rejection', static function (UserAdminRepositoryInterface $repo): void {
        $repo->transaction(static function (UserTransactionInterface $tx): void {
            $tx->appendAudit(1, 3, 'user.updated', ['roles' => ['from' => ['student'], 'to' => ['faculty']]], '2023-11-14T22:13:20Z');
            $tx->appendAudit(1, 0, 'user.created', ['reason' => 'denied'], '2023-11-14T22:13:21Z', 'denied');
        });
        $entries = $repo->auditEntries();
        same([1, 2], array_column($entries, 'id'));
        same(['id', 'at', 'actor_id', 'target_id', 'action', 'outcome', 'changes'], array_keys($entries[0]));
        same(['2023-11-14T22:13:20Z', 1, 3, 'user.updated', 'success',
            ['roles' => ['from' => ['student'], 'to' => ['faculty']]]],
            [$entries[0]['at'], $entries[0]['actor_id'], $entries[0]['target_id'], $entries[0]['action'], $entries[0]['outcome'], $entries[0]['changes']]);
        same('denied', $entries[1]['outcome']);
        foreach ([[['note' => 'x'], 'success', 'Refusing an unsupported audit entry.'],
            [['reason' => 'x'], 'maybe', 'Refusing an unsupported audit entry.'],
            [['reason' => ['password_hash' => 'x']], 'denied', 'Refusing to audit a secret field.']] as [$changes, $outcome, $message]) {
            same($message, throws(RuntimeException::class, static fn () => $repo->transaction(
                static fn (UserTransactionInterface $tx) => $tx->appendAudit(1, 3, 'user.updated', $changes,
                    '2023-11-14T22:13:22Z', $outcome)))->getMessage());
        }
        same(2, count($repo->auditEntries()));
    });

    $case('searchAudit: newest first, filters, inclusive dates, pagination', static function (UserAdminRepositoryInterface $repo): void {
        $repo->transaction(static function (UserTransactionInterface $tx): void {
            $tx->appendAudit(1, 3, 'user.updated', ['status' => 'x'], '2024-01-01T00:00:00Z');
            $tx->appendAudit(1, 0, 'user.created', ['reason' => 'denied'], '2024-01-02T00:00:00Z', 'denied');
            $tx->appendAudit(2, 3, 'user.updated', ['status' => 'y'], '2024-01-03T00:00:00Z');
        });
        $ids = static fn (array $filters, int $page = 1, int $per = 10): array
            => array_column($repo->searchAudit($filters, $page, $per)['entries'], 'id');
        same([3, 2, 1], $ids([]));
        same(3, $repo->searchAudit([], 1, 1)['total']);
        same([2], $ids([], 2, 1));
        same([], $ids([], 5, 1));
        same([3, 1], $ids(['action' => 'user.updated']));
        same([2], $ids(['outcome' => 'denied']));
        same([3], $ids(['actor_id' => 2]));
        same([2], $ids(['target_id' => 0]));
        same([3, 1], $ids(['target_id' => 3]));
        same([2, 1], $ids(['to' => '2024-01-02T00:00:00Z']));
        same([3, 2], $ids(['from' => '2024-01-02T00:00:00Z']));
        same([2], $ids(['from' => '2024-01-02T00:00:00Z', 'to' => '2024-01-02T00:00:00Z']));
        same([1], $ids(['action' => 'user.updated', 'actor_id' => 1, 'from' => '2023-01-01T00:00:00Z', 'to' => '2024-01-02T23:59:59Z']));
        $entry = $repo->searchAudit([], 1, 1)['entries'][0];
        same(['id', 'at', 'actor_id', 'target_id', 'action', 'outcome', 'changes'], array_keys($entry));
        same('2024-01-03T00:00:00Z', $entry['at']);
    });

    $case('throw rolls back user, roles, audit, and counter', static function (UserAdminRepositoryInterface $repo): void {
        throws(RuntimeException::class, static fn () => $repo->transaction(static function (UserTransactionInterface $tx): void {
            $tx->update(3, ['roles' => ['faculty'], 'status' => 'inactive']);
            $tx->appendAudit(1, 3, 'user.updated', ['status' => 'inactive'], '2023-11-14T22:13:20Z');
            throw new RuntimeException('test failure');
        }));
        same(['student'], $repo->findById(3)->roles);
        same('active', $repo->findById(3)->status);
        same([], $repo->auditEntries());
        $repo->transaction(static fn (UserTransactionInterface $tx) => $tx->appendAudit(1, 3, 'user.updated',
            ['status' => 'active'], '2023-11-14T22:13:21Z'));
        same(1, $repo->auditEntries()[0]['id']);
        same('Nested store operations are not supported.', throws(RuntimeException::class,
            static fn () => $repo->transaction(static fn () => $repo->transaction(static fn () => null)))->getMessage());
    });
}

function userContractHash(): string
{
    static $hash = null;
    return $hash ??= password_hash('Contract-passphrase-42', PASSWORD_DEFAULT);
}

/** @return list<array> */
function userContractRows(): array
{
    return [
        ['id' => 1, 'email' => 'admin@example.test', 'roles' => ['admin'], 'status' => 'active', 'password_hash' => userContractHash(), 'auth_version' => 1],
        ['id' => 2, 'email' => 'second-admin@example.test', 'roles' => ['admin'], 'status' => 'active', 'password_hash' => userContractHash(), 'auth_version' => 1],
        ['id' => 3, 'email' => 'student@example.test', 'roles' => ['student'], 'status' => 'active', 'password_hash' => userContractHash(), 'auth_version' => 1],
    ];
}
