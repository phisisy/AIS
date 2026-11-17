<?php
declare(strict_types=1);

namespace App\Auth\Repositories;

use App\Auth\Models\User;

/**
 * Reads and writes inside one exclusive, atomic unit: either every change plus its audit
 * entries commits, or nothing does. A MySQL implementation (T-40) must give the same
 * guarantee (transaction + row locks on the users/roles it reads for decisions).
 */
interface UserTransactionInterface
{
    public function find(int $id): ?User;

    public function findByEmail(string $email): ?User;

    /** Assigns the id; 409 on duplicate email. */
    public function insert(string $email, array $roles, string $status, string $passwordHash): User;

    /** $changes keys: email, roles, status, password_hash. A password change or deactivation bumps auth_version. 409 on duplicate email. */
    public function update(int $id, array $changes): User;

    /**
     * $outcome: success | denied. Top-level $changes keys must come from AUDIT_KEYS; keys naming
     * passwords, hashes, tokens or sessions are refused at any depth.
     */
    public function appendAudit(int $actorId, int $targetId, string $action, array $changes, string $at, string $outcome = 'success'): void;

    public const AUDIT_KEYS = ['email', 'roles', 'status', 'signed_out_everywhere', 'reason'];
}
