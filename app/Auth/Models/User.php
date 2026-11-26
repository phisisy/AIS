<?php
declare(strict_types=1);

namespace App\Auth\Models;

use JsonSerializable;
use RuntimeException;

final class User implements JsonSerializable
{
    public readonly string $email;

    public function __construct(
        public readonly int $id,
        string $email,
        public readonly array $roles,
        public readonly string $status,
        private readonly string $passwordHash,
        public readonly int $authVersion = 1,
    ) {
        $this->email = strtolower(trim($email));
        if ($id < 1 || $authVersion < 1
            || strlen($this->email) > 254 || filter_var($this->email, FILTER_VALIDATE_EMAIL) === false
            || !in_array($status, ['active', 'inactive'], true)
            || !array_is_list($roles) || $roles === []
            || count(array_unique($roles, SORT_REGULAR)) !== count($roles)
            || password_get_info($passwordHash)['algoName'] === 'unknown') {
            throw new RuntimeException('Invalid stored user.');
        }
        foreach ($roles as $role) {
            if (!is_string($role) || !in_array($role, ['student', 'faculty', 'registrar', 'admin'], true)) {
                throw new RuntimeException('Invalid stored user.');
            }
        }
    }

    public static function fromArray(array $row): self
    {
        foreach (['id', 'email', 'roles', 'status', 'password_hash', 'auth_version'] as $key) {
            if (!array_key_exists($key, $row)) {
                throw new RuntimeException('Invalid stored user.');
            }
        }
        if (!is_int($row['id']) || !is_string($row['email']) || !is_array($row['roles'])
            || !is_string($row['status']) || !is_string($row['password_hash']) || !is_int($row['auth_version'])) {
            throw new RuntimeException('Invalid stored user.');
        }
        return new self($row['id'], $row['email'], $row['roles'], $row['status'], $row['password_hash'], $row['auth_version']);
    }

    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->passwordHash);
    }

    public function toPublicArray(): array
    {
        return ['id' => $this->id, 'email' => $this->email, 'roles' => $this->roles, 'status' => $this->status];
    }

    public function jsonSerialize(): array
    {
        return $this->toPublicArray();
    }
}
