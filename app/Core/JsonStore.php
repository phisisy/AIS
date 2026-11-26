<?php
declare(strict_types=1);

namespace App\Core;

use JsonException;
use RuntimeException;

/** Development-only storage. Use one store for every row in a transaction. */
final class JsonStore
{
    /** About 1.1 s of total back-off before giving up. */
    private const REPLACE_ATTEMPTS = 10;

    private bool $busy = false;

    public function __construct(private string $path)
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
            throw new RuntimeException('Invalid store path.');
        }
    }

    public function read(): array
    {
        return $this->locked(LOCK_SH, fn (): array => $this->readUnlocked());
    }

    /**
     * The callback receives array &$data and may return any value.
     * Commit only after it returns successfully. Do not nest operations on this
     * store (including another JsonStore pointing at the same file).
     * With $wait = false the call fails immediately ('Store is busy.') instead of
     * queueing behind another writer; use it only for best-effort data.
     */
    public function transaction(callable $callback, bool $wait = true): mixed
    {
        return $this->locked($wait ? LOCK_EX : LOCK_EX | LOCK_NB, function () use ($callback): mixed {
            $data = $this->readUnlocked();
            $result = $callback($data);
            if (!is_array($data)) {
                throw new RuntimeException('Store data must be an array.');
            }
            $this->writeUnlocked($data);
            return $result;
        });
    }

    private function locked(int $operation, callable $callback): mixed
    {
        if ($this->busy) {
            throw new RuntimeException('Nested store operations are not supported.');
        }
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create store directory.');
        }
        // This file must remain stable: locking the replaced JSON inode is unsafe.
        $lock = @fopen($this->path . '.lock', 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Unable to open store lock.');
        }
        $this->busy = true;
        try {
            if (!flock($lock, $operation, $wouldBlock)) {
                throw new RuntimeException(($operation & LOCK_NB) && $wouldBlock ? 'Store is busy.' : 'Unable to lock store.');
            }
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            $this->busy = false;
        }
    }

    private function readUnlocked(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }
        $contents = @file_get_contents($this->path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read store.');
        }
        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Invalid store data.');
        }
        if (!is_array($data)) {
            throw new RuntimeException('Invalid store data.');
        }
        return $data;
    }

    private function writeUnlocked(array $data): void
    {
        try {
            $contents = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new RuntimeException('Unable to encode store data.');
        }
        $temporary = @tempnam(dirname($this->path), '.ais-store-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create store temporary file.');
        }
        // tempnam() silently falls back to the system temp dir; never stage data outside the store dir.
        if (realpath(dirname($temporary)) !== realpath(dirname($this->path))) {
            @unlink($temporary);
            throw new RuntimeException('Unable to create store temporary file.');
        }
        try {
            $written = @file_put_contents($temporary, $contents);
            if ($written !== strlen($contents)) {
                throw new RuntimeException('Unable to write store.');
            }
            // Windows refuses to replace a file another process has open (OneDrive sync,
            // antivirus). Retry briefly; on final failure the original file is untouched.
            for ($attempt = 1; !@rename($temporary, $this->path); $attempt++) {
                if ($attempt >= self::REPLACE_ATTEMPTS) {
                    throw new RuntimeException('Unable to replace store.');
                }
                usleep(20000 * $attempt);
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
