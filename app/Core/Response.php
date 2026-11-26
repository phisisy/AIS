<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

final class Response
{
    public readonly string $body;
    public readonly array $headers;

    private function __construct(array $payload, public readonly int $status, array $headers = [])
    {
        if ($status < 200 || $status > 599) {
            throw new InvalidArgumentException('Invalid response status.');
        }
        foreach ($headers as $name => $value) {
            if (!preg_match('/\A[A-Za-z0-9-]+\z/', $name) || preg_match('/[\r\n\x00]/', $value)) {
                throw new InvalidArgumentException('Invalid response header.');
            }
            if (in_array(strtolower($name), ['content-type', 'cache-control', 'x-content-type-options'], true)) {
                unset($headers[$name]);
            }
        }
        $this->headers = array_merge($headers, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        // Encode before sending headers so encoding failures become a clean 500.
        $this->body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function success(mixed $data = [], string $message = 'OK', int $status = 200): self
    {
        return new self(['success' => true, 'message' => $message, 'data' => $data === [] ? (object) [] : $data], $status);
    }

    public static function error(string $message, int $status, array $errors = [], array $headers = []): self
    {
        return new self(['success' => false, 'message' => $message, 'errors' => (object) $errors], $status, $headers);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        echo $this->body;
    }
}
