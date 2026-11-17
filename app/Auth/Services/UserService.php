<?php
declare(strict_types=1);

namespace App\Auth\Services;

use App\Auth\Models\User;
use App\Auth\Repositories\UserAdminRepositoryInterface;
use App\Auth\Repositories\UserTransactionInterface;
use App\Core\HttpException;
use Closure;

/**
 * Administrator account operations (T-03). Routes are admin-guarded; every mutation
 * re-checks the acting admin inside the same exclusive transaction that writes the change
 * and its audit entry, so a concurrent demotion/deactivation of the actor is honoured.
 */
final class UserService
{
    private const ADMIN_ROLES = ['admin'];
    private Closure $clock;

    public function __construct(
        private readonly UserAdminRepositoryInterface $users,
        private readonly AuthorizationService $authorization,
        private readonly LoginRateLimiter $limiter,
        ?callable $clock = null,
    ) {
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }

    public function list(User $actor, array $query): array
    {
        $this->authorization->requireRole($actor, self::ADMIN_ROLES);
        $search = $query['search'] ?? '';
        $page = filter_var($query['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1_000_000]]);
        $perPage = filter_var($query['per_page'] ?? '20', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $errors = [];
        if (!is_string($search) || strlen($search) > 254) {
            $errors['search'] = 'Use at most 254 characters.';
        }
        if ($page === false) {
            $errors['page'] = 'Use a whole number from 1 to 1000000.';
        }
        if ($perPage === false) {
            $errors['per_page'] = 'Use a whole number from 1 to 100.';
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Validation failed.', $errors);
        }
        $result = $this->users->search($search, $page, $perPage);
        return [
            'users' => array_map(static fn (User $user): array => $user->toPublicArray(), $result['users']),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $result['total']],
        ];
    }

    public function get(User $actor, int $id): array
    {
        $this->authorization->requireRole($actor, self::ADMIN_ROLES);
        return ($this->users->findById($id) ?? throw new HttpException(404, 'User not found.'))->toPublicArray();
    }

    /** Target id 0 = the account that would have been created. */
    public function create(User $actor, array $input): array
    {
        return $this->audited($actor, 0, 'user.created', fn (): array => $this->doCreate($actor, $input));
    }

    /** Email and/or the full role set. Role assignment and removal are both expressed as the new set. */
    public function update(User $actor, int $id, array $input): array
    {
        return $this->audited($actor, $id, 'user.updated', fn (): array => $this->doUpdate($actor, $id, $input));
    }

    public function changeStatus(User $actor, int $id, array $input): array
    {
        $action = ($input['status'] ?? null) === 'active' ? 'user.activated' : 'user.deactivated';
        return $this->audited($actor, $id, $action, fn (): array => $this->doChangeStatus($actor, $id, $input));
    }

    /** Requires the acting admin's current password (rate-limited); ends the target's sessions via auth_version. */
    public function resetPassword(User $actor, int $id, array $input, string $ip): array
    {
        return $this->audited($actor, $id, 'user.password_reset', fn (): array => $this->doResetPassword($actor, $id, $input, $ip));
    }

    private function doCreate(User $actor, array $input): array
    {
        $this->authorization->requireRole($actor, self::ADMIN_ROLES);
        $this->requireFields($input, ['email', 'password', 'roles']);
        $errors = [];
        $email = $this->email($input['email'], $errors);
        $roles = $this->roles($input['roles'], $errors);
        $status = array_key_exists('status', $input) ? $this->status($input['status'], $errors) : 'active';
        $password = $this->newPassword($input['password'], $errors);
        $this->throwIfErrors($errors);
        $hash = AuthService::hashPassword($password);

        return $this->users->transaction(function (UserTransactionInterface $tx) use ($actor, $email, $roles, $status, $hash): array {
            $this->freshAdmin($tx, $actor);
            $user = $tx->insert($email, $roles, $status, $hash);
            $tx->appendAudit($actor->id, $user->id, 'user.created', ['email' => $email, 'roles' => $roles, 'status' => $status], $this->now());
            return $user->toPublicArray();
        });
    }

    private function doUpdate(User $actor, int $id, array $input): array
    {
        $this->authorization->requireRole($actor, self::ADMIN_ROLES);
        if ($input === []) {
            throw new HttpException(422, 'Validation failed.', ['body' => 'Provide email and/or roles.']);
        }
        $errors = [];
        $changes = [];
        if (array_key_exists('email', $input)) {
            $changes['email'] = $this->email($input['email'], $errors);
        }
        if (array_key_exists('roles', $input)) {
            $changes['roles'] = $this->roles($input['roles'], $errors);
        }
        $this->throwIfErrors($errors);

        return $this->users->transaction(function (UserTransactionInterface $tx) use ($actor, $id, $changes): array {
            $this->freshAdmin($tx, $actor);
            $target = $tx->find($id) ?? throw new HttpException(404, 'User not found.');
            $before = $target->toPublicArray();
            sort($before['roles']);
            $diff = $this->diff($before, $changes);
            if (isset($diff['roles']) && $target->id === $actor->id) {
                throw new HttpException(409, 'Administrators cannot change their own roles.');
            }
            if ($diff === []) {
                return $target->toPublicArray();
            }
            $after = $tx->update($id, array_intersect_key($changes, $diff));
            $tx->appendAudit($actor->id, $id, 'user.updated', $diff, $this->now());
            return $after->toPublicArray();
        });
    }

    private function doChangeStatus(User $actor, int $id, array $input): array
    {
        $this->authorization->requireRole($actor, self::ADMIN_ROLES);
        $this->requireFields($input, ['status']);
        $errors = [];
        $status = $this->status($input['status'], $errors);
        $this->throwIfErrors($errors);

        return $this->users->transaction(function (UserTransactionInterface $tx) use ($actor, $id, $status): array {
            $this->freshAdmin($tx, $actor);
            $target = $tx->find($id) ?? throw new HttpException(404, 'User not found.');
            if ($target->id === $actor->id) {
                throw new HttpException(409, 'Administrators cannot change their own status.');
            }
            if ($target->status === $status) {
                return $target->toPublicArray();
            }
            $after = $tx->update($id, ['status' => $status]);
            $tx->appendAudit($actor->id, $id, $status === 'active' ? 'user.activated' : 'user.deactivated',
                ['status' => ['from' => $target->status, 'to' => $status]], $this->now());
            return $after->toPublicArray();
        });
    }

    private function doResetPassword(User $actor, int $id, array $input, string $ip): array
    {
        $this->authorization->requireRole($actor, self::ADMIN_ROLES);
        $this->requireFields($input, ['password', 'current_password']);
        $errors = [];
        $password = $this->newPassword($input['password'], $errors);
        $this->throwIfErrors($errors);
        $this->limiter->consume($ip, 'reauth:' . $actor->email);
        $current = $input['current_password'];
        $fresh = $this->users->findById($actor->id);
        if (!is_string($current) || strlen($current) > 72 || str_contains($current, "\0")
            || $fresh === null || !$fresh->verifyPassword($current)) {
            throw new HttpException(403, 'Current password confirmation failed.');
        }
        $hash = AuthService::hashPassword($password);

        return $this->users->transaction(function (UserTransactionInterface $tx) use ($actor, $id, $hash): array {
            $this->freshAdmin($tx, $actor);
            $target = $tx->find($id) ?? throw new HttpException(404, 'User not found.');
            if ($target->id === $actor->id) {
                throw new HttpException(409, 'Administrators cannot reset their own password here.');
            }
            $after = $tx->update($id, ['password_hash' => $hash]);
            $tx->appendAudit($actor->id, $id, 'user.password_reset', ['signed_out_everywhere' => true], $this->now());
            return $after->toPublicArray();
        });
    }

    /** Re-reads the actor under the lock: a concurrent demotion, deactivation or password reset wins. */
    private function freshAdmin(UserTransactionInterface $tx, User $actor): void
    {
        $current = $tx->find($actor->id);
        if ($current === null || $current->status !== 'active' || $current->authVersion !== $actor->authVersion) {
            throw new HttpException(401, 'Authentication required.');
        }
        $this->authorization->requireRole($current, self::ADMIN_ROLES);
    }

    /**
     * Runs a mutation; if it is refused (401/403/404/409/429), records a separate `denied`
     * audit entry with a reason code and no input values, then rethrows. A failure to write
     * that entry never hides the original error. Validation errors (422) are not audited.
     */
    private function audited(User $actor, int $targetId, string $action, callable $operation): array
    {
        try {
            return $operation();
        } catch (HttpException $exception) {
            $reason = self::denialReason($exception);
            if ($reason !== null) {
                try {
                    $this->users->transaction(fn (UserTransactionInterface $tx) => $tx->appendAudit(
                        $actor->id, $targetId, $action, ['reason' => $reason], $this->now(), 'denied'));
                } catch (\Throwable) {
                    // Deliberately ignored: the caller still receives the original refusal.
                }
            }
            throw $exception;
        }
    }

    private static function denialReason(HttpException $exception): ?string
    {
        return match (true) {
            $exception->status === 401 => 'actor_not_signed_in_or_inactive',
            $exception->status === 403 && $exception->getMessage() === 'Current password confirmation failed.' => 'confirmation_failed',
            $exception->status === 403 => 'actor_not_admin',
            $exception->status === 404 => 'target_not_found',
            $exception->status === 409 && $exception->getMessage() === UserAdminRepositoryInterface::LAST_ADMIN_MESSAGE => 'last_active_admin',
            $exception->status === 409 && isset($exception->errors['email']) => 'duplicate_email',
            $exception->status === 409 => 'self_change_not_allowed',
            $exception->status === 429 => 'rate_limited',
            default => null,
        };
    }

    private function diff(array $before, array $changes): array
    {
        $diff = [];
        foreach ($changes as $key => $value) {
            if ($before[$key] !== $value) {
                $diff[$key] = ['from' => $before[$key], 'to' => $value];
            }
        }
        return $diff;
    }

    private function requireFields(array $input, array $required): void
    {
        $errors = [];
        foreach ($required as $field) {
            if (!array_key_exists($field, $input)) {
                $errors[$field] = 'This field is required.';
            }
        }
        $this->throwIfErrors($errors);
    }

    private function throwIfErrors(array $errors): void
    {
        if ($errors !== []) {
            throw new HttpException(422, 'Validation failed.', $errors);
        }
    }

    private function email(mixed $value, array &$errors): string
    {
        $email = is_string($value) ? strtolower(trim($value)) : '';
        if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Provide a valid email address (at most 254 characters).';
        }
        return $email;
    }

    private function roles(mixed $value, array &$errors): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            $errors['roles'] = 'Provide a non-empty list of roles.';
            return [];
        }
        foreach ($value as $role) {
            if (!is_string($role) || !in_array($role, AuthorizationService::ROLES, true)) {
                $errors['roles'] = 'Use only: ' . implode(', ', AuthorizationService::ROLES) . '.';
                return [];
            }
        }
        $roles = array_values(array_unique($value));
        sort($roles);
        return $roles;
    }

    private function status(mixed $value, array &$errors): string
    {
        if (!in_array($value, ['active', 'inactive'], true)) {
            $errors['status'] = 'Use active or inactive.';
            return '';
        }
        return $value;
    }

    private function newPassword(mixed $value, array &$errors): string
    {
        if (!is_string($value) || strlen($value) < 12 || strlen($value) > 72 || str_contains($value, "\0") || trim($value) === '') {
            $errors['password'] = 'Use 12 to 72 bytes; a passphrase is recommended.';
            return '';
        }
        return $value;
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', ($this->clock)());
    }
}
