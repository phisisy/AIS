<?php
declare(strict_types=1);

namespace App\Core;

use JsonException;
use RuntimeException;
use stdClass;

final class Request
{
    public const MAX_BODY_BYTES = 65536;

    public readonly string $method;
    public readonly string $path;
    public readonly array $query;
    private array $headers = [];
    private array $body = [];

    public function __construct(string $method, string $uri, array $headers = [], string $body = '')
    {
        $this->method = strtoupper($method);
        // Split the origin-form target without decoding slashes or accepting absolute URLs.
        $parts = explode('?', $uri, 2);
        if (!str_starts_with($parts[0], '/') || preg_match('/[\x00-\x20\x7f#]/', $parts[0])) {
            throw new HttpException(400, 'Invalid request path.');
        }
        $this->path = $parts[0];
        parse_str($parts[1] ?? '', $query);
        $this->query = $query;
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = trim((string) $value);
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'Request body is too large.');
        }
        if ($body === '') {
            return;
        }
        $this->requireJsonContentType();
        try {
            $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'Malformed JSON body.');
        }
        if (!$decoded instanceof stdClass) {
            throw new HttpException(422, 'The JSON body must be an object.');
        }
        // Preserve nested JSON objects versus arrays so services can validate types.
        $this->body = get_object_vars($decoded);
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            }
        }
        $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'] ?? '';
        $length = $_SERVER['CONTENT_LENGTH'] ?? '0';
        if (is_numeric($length) && (float) $length > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'Request body is too large.');
        }
        // PHP can consume multipart bodies before php://input is read.
        $hasBody = (is_numeric($length) && (float) $length > 0)
            || isset($_SERVER['HTTP_TRANSFER_ENCODING']) || !empty($_POST) || !empty($_FILES);
        if ($hasBody
            && strtolower(trim(explode(';', $headers['Content-Type'], 2)[0])) !== 'application/json') {
            throw new HttpException(415, 'Content-Type must be application/json.');
        }
        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to read request.');
        }
        try {
            $body = stream_get_contents($stream, self::MAX_BODY_BYTES + 1);
            if ($body === false) {
                throw new RuntimeException('Unable to read request.');
            }
        } finally {
            fclose($stream);
        }
        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/', $headers, $body);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function json(?array $allowedFields = null): array
    {
        if ($allowedFields !== null) {
            $errors = [];
            foreach (array_diff(array_keys($this->body), $allowedFields) as $field) {
                $errors[$field] = 'Unknown field.';
            }
            if ($errors !== []) {
                throw new HttpException(422, 'Validation failed.', $errors);
            }
        }
        return $this->body;
    }

    private function requireJsonContentType(): void
    {
        $type = strtolower(trim(explode(';', $this->header('Content-Type') ?? '', 2)[0]));
        if ($type !== 'application/json') {
            throw new HttpException(415, 'Content-Type must be application/json.');
        }
    }
}
