<?php
declare(strict_types=1);

namespace App\Auth\Services;

use App\Auth\Models\User;
use App\Auth\Repositories\UserRepositoryInterface;
use App\Auth\SessionInterface;
use App\Core\HttpException;

final class AuthService
{
    /**
     * Pinned (not PASSWORD_DEFAULT) so stored hashes and DUMMY_HASH always cost the same:
     * PHP 8.4 raised the default bcrypt cost, which would make unknown emails measurably faster.
     */
    public const HASH_ALGO = PASSWORD_BCRYPT;
    public const HASH_OPTIONS = ['cost' => 10];
    /** Verified for unknown emails so every failure costs one hash check. Must match HASH_*. */
    public const DUMMY_HASH = '$2y$10$EKPP.0cBB1N1xTo9fCPLE.eDjhRnpsQpPJ2B7Tii7VhKu1LHH.Ejq';
    private const FAILURE = 'Invalid email or password.';

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly SessionInterface $session,
        private readonly LoginRateLimiter $limiter,
    ) {
    }

    /** The only way new password hashes should be created (seeder now, user admin in T-03). */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, self::HASH_ALGO, self::HASH_OPTIONS);
    }

    /** Every credential failure (unknown, wrong, inactive, wrong type) returns the same 401. */
    public function login(mixed $email, mixed $password, string $ip): User
    {
        $email = is_string($email) ? strtolower(trim($email)) : '';
        $password = is_string($password) ? $password : '';
        $this->limiter->consume($ip, substr($email, 0, 254));

        $validEmail = $email !== '' && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        $validPassword = $password !== '' && strlen($password) <= 72 && !str_contains($password, "\0");
        $user = $validEmail ? $this->users->findByEmail($email) : null;
        $candidate = $validPassword ? $password : '';
        $matches = $user !== null ? $user->verifyPassword($candidate) : password_verify($candidate, self::DUMMY_HASH);

        if (!$validPassword || !$matches || $user === null || $user->status !== 'active') {
            throw new HttpException(401, self::FAILURE);
        }
        $this->session->login($user->id, $user->authVersion);
        return $user;
    }

    /** Reloads the account on every call; a stale or revoked session is destroyed. */
    public function currentUser(): ?User
    {
        $identity = $this->session->identity();
        if ($identity === null) {
            return null;
        }
        $user = $this->users->findById($identity['user_id']);
        if ($user === null || $user->status !== 'active' || $user->authVersion !== $identity['auth_version']) {
            $this->session->destroy();
            return null;
        }
        return $user;
    }

    public function requireUser(): User
    {
        return $this->currentUser() ?? throw new HttpException(401, 'Authentication required.');
    }

    public function logout(): void
    {
        $this->requireUser();
        $this->session->destroy();
    }
}
