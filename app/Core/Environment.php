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

    /** Process-only list of keys that read as '' even when the OS dropped the empty value (Windows does). */
    public const BLANK_KEYS = 'AIS_ENV_BLANK';

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        // N1 on Windows: an explicitly blanked key must never fall back to .env.
        if (in_array($key, self::blankKeys(), true)) {
            return '';
        }
        return self::$values[$key] ?? $default;
    }

    /**
     * Environment for a child process. Every key whose value is '' is also listed in
     * AIS_ENV_BLANK, because Windows drops empty variables when it starts a process.
     * Keys already blanked by this process stay blanked unless $environment sets a value.
     */
    public static function forChild(array $environment): array
    {
        $given = $environment[self::BLANK_KEYS] ?? false;
        $blank = array_merge(self::blankKeys(), self::parseBlankKeys(is_string($given) || $given === false ? $given : '!'));
        foreach ($environment as $key => $value) {
            // Inherited names may be OS-specific (e.g. Windows "ProgramFiles(x86)"); only blanked names must be plain.
            if (!is_string($key) || $key === '' || !is_string($value)
                // Blanked names are uppercase-only: Windows names are case-insensitive, matching here is not.
                || ($value === '' && !preg_match('/\A[A-Z_][A-Z0-9_]*\z/D', $key))) {
                throw new RuntimeException('Invalid child environment.');
            }
            if ($value === '') {
                $blank[] = $key;
            } elseif ($key !== self::BLANK_KEYS) {
                $blank = array_values(array_diff($blank, [$key]));
            }
        }
        $blank = array_values(array_unique(array_diff($blank, [self::BLANK_KEYS])));
        sort($blank);
        unset($environment[self::BLANK_KEYS]);
        if ($blank !== []) {
            $environment[self::BLANK_KEYS] = implode(',', $blank);
        }
        return $environment;
    }

    /** @return list<string> Fails closed on a malformed list, so a typo cannot silently re-enable .env secrets. */
    private static function blankKeys(): array
    {
        return self::parseBlankKeys(getenv(self::BLANK_KEYS));
    }

    /** @return list<string> */
    private static function parseBlankKeys(string|false $list): array
    {
        if ($list === false || $list === '') {
            return [];
        }
        if (!preg_match('/\A[A-Z_][A-Z0-9_]*(,[A-Z_][A-Z0-9_]*)*\z/D', $list)) {
            throw new RuntimeException('Invalid environment configuration.');
        }
        return explode(',', $list);
    }
}
