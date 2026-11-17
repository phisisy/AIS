<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Environment
{
    private static array $values = [];

    /**
     * Load KEY=value lines, with optional matching single/double quotes.
     * Blank lines and whole-line # comments are allowed. Values are literal:
     * no interpolation, escape processing, export syntax, or inline comments.
     * Process environment always wins, including an explicitly empty value.
     */
    public static function load(string $path): void
    {
        self::$values = [];
        if (!file_exists($path)) {
            return;
        }
        $lines = is_file($path) ? @file($path, FILE_IGNORE_NEW_LINES) : false;
        if ($lines === false) {
            throw new RuntimeException('Unable to read environment configuration.');
        }
        $values = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/\A([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)\z/D', $line, $parts)
                || str_contains($line, "\0") || array_key_exists($parts[1], $values)) {
                throw new RuntimeException('Invalid environment configuration.');
            }
            $value = trim($parts[2]);
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                if (strlen($value) < 2 || substr($value, -1) !== $value[0]) {
                    throw new RuntimeException('Invalid environment configuration.');
                }
                $value = substr($value, 1, -1);
            }
            $values[$parts[1]] = $value;
        }
        self::$values = $values;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return $value !== false ? $value : (self::$values[$key] ?? $default);
    }
}
