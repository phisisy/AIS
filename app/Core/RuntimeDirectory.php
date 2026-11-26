<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Validates the private runtime directory (sessions, mock stores) before anything writes to it. */
final class RuntimeDirectory
{
    /**
     * Returns the resolved absolute path, creating it (0700) when missing.
     * Fails closed: relative, unwritable, non-directory or web-public paths are refused.
     */
    public static function prepare(string $path, string $publicDirectory): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '://')
            || !preg_match('~\A(?:[A-Za-z]:[\\\\/]|[\\\\/])~', $path)) {
            throw new RuntimeException('Invalid runtime directory.');
        }
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Runtime directory unavailable.');
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved) || !is_writable($resolved)) {
            throw new RuntimeException('Runtime directory unavailable.');
        }
        $public = realpath($publicDirectory);
        if ($public !== false && self::within($resolved, $public)) {
            throw new RuntimeException('Runtime directory must not be web-public.');
        }
        return $resolved;
    }

    private static function within(string $path, string $parent): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }
        return $path === $parent || str_starts_with($path, rtrim($parent, '\\/') . DIRECTORY_SEPARATOR);
    }
}
