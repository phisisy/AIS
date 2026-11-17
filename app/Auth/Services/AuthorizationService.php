<?php
declare(strict_types=1);

namespace App\Auth\Services;

use App\Auth\Models\User;
use App\Core\HttpException;
use InvalidArgumentException;

/** Role checks only. Record ownership stays with each module's service. */
final class AuthorizationService
{
    public const ROLES = ['student', 'faculty', 'registrar', 'admin'];

    /** For route registration: a typo'd or empty allowlist is a programming error, not "allow nobody quietly". */
    public static function validAllowlist(array $roles): array
    {
        if ($roles === [] || !array_is_list($roles)) {
            throw new InvalidArgumentException('Role allowlist must be a non-empty list.');
        }
        foreach ($roles as $role) {
            if (!is_string($role) || !in_array($role, self::ROLES, true)) {
                throw new InvalidArgumentException('Unknown role in allowlist.');
            }
        }
        return array_values(array_unique($roles));
    }

    /**
     * 401 for inactive users even when called directly; 403 unless the user holds a
     * known role from the allowlist. Empty or unknown-only allowlists grant nobody.
     */
    public function requireRole(User $user, array $allowed): void
    {
        if ($user->status !== 'active') {
            throw new HttpException(401, 'Authentication required.');
        }
        if (!$this->hasAnyRole($user, $allowed)) {
            throw new HttpException(403, 'Access denied.');
        }
    }

    public function hasAnyRole(User $user, array $allowed): bool
    {
        foreach ($allowed as $role) {
            if (is_string($role) && in_array($role, self::ROLES, true) && in_array($role, $user->roles, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Horizontal check for module services. $recordOwnerUserId must be the stored record's
     * account id (e.g. student.user_id) loaded from storage — never a route parameter, which
     * is a profile id. $staffRoles may be [] (owner only); otherwise it is validated like a route allowlist.
     */
    public function isOwnerOrHasRole(User $user, int $recordOwnerUserId, array $staffRoles): bool
    {
        if ($staffRoles !== []) {
            self::validAllowlist($staffRoles);
        }
        return $user->status === 'active' && ($user->id === $recordOwnerUserId || $this->hasAnyRole($user, $staffRoles));
    }

    /** As isOwnerOrHasRole, but throws 404 so other users' records are indistinguishable from missing ones. */
    public function requireOwnerOrRole(User $user, int $recordOwnerUserId, array $staffRoles): void
    {
        if (!$this->isOwnerOrHasRole($user, $recordOwnerUserId, $staffRoles)) {
            throw new HttpException(404, 'Not found.');
        }
    }
}
