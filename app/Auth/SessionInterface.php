<?php
declare(strict_types=1);

namespace App\Auth;

interface SessionInterface
{
    public function start(): void;

    public function csrfToken(): string;

    public function assertCsrf(?string $token): void;

    public function login(int $userId, int $authVersion): void;

    /** @return array{user_id: int, auth_version: int}|null */
    public function identity(): ?array;

    public function destroy(): void;

    public function close(): void;
}
