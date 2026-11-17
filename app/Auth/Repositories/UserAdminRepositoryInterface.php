<?php
declare(strict_types=1);

namespace App\Auth\Repositories;

/** Administration capability on top of the T-01 lookup contract. */
interface UserAdminRepositoryInterface extends UserRepositoryInterface
{
    public const LAST_ADMIN_MESSAGE = 'The last active administrator cannot be deactivated or lose the admin role.';

    /** @return array{users: list<\App\Auth\Models\User>, total: int} ordered by id; case-insensitive email substring. */
    public function search(string $emailContains, int $page, int $perPage): array;

    /**
     * Runs $operation(UserTransactionInterface $tx) atomically and exclusively.
     * Enforces at commit: if an active admin existed before, one must exist after (else 409).
     */
    public function transaction(callable $operation): mixed;

    /** @return list<array> audit entries, oldest first. */
    public function auditEntries(): array;
}
