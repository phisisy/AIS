<?php
declare(strict_types=1);

namespace App\Auth\Services;

use App\Core\HttpException;
use App\Core\JsonStore;
use Closure;
use RuntimeException;

final class LoginRateLimiter
{
    private const WINDOW_SECONDS = 900;
    private Closure $clock;

    public function __construct(private readonly JsonStore $store, ?callable $clock = null)
    {
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }

    /**
     * Reserve an attempt before password verification. Both successful and failed
     * credential attempts count. Denied requests do not extend either window.
     */
    public function consume(string $ip, string $normalizedEmail): void
    {
        $address = @inet_pton($ip);
        $now = ($this->clock)();
        if ($address === false || !is_int($now) || $now < 0) {
            throw new RuntimeException('Invalid rate limit context.');
        }
        $ipKey = hash('sha256', $address);
        $pairKey = hash('sha256', $address . "\0" . strtolower(trim($normalizedEmail)));
        $retry = $this->store->transaction(function (array &$data) use ($now, $ipKey, $pairKey): int {
            if ($data === []) {
                $data = ['ip' => [], 'pair' => []];
            }
            foreach (['ip' => 30, 'pair' => 5] as $kind => $limit) {
                if (!isset($data[$kind]) || !is_array($data[$kind])) {
                    throw new RuntimeException('Invalid rate limit store.');
                }
                foreach ($data[$kind] as $key => $timestamps) {
                    if (!is_string($key) || !preg_match('/\A[a-f0-9]{64}\z/', $key)
                        || !is_array($timestamps) || !array_is_list($timestamps) || count($timestamps) > $limit) {
                        throw new RuntimeException('Invalid rate limit store.');
                    }
                    foreach ($timestamps as $timestamp) {
                        if (!is_int($timestamp) || $timestamp < 0) {
                            throw new RuntimeException('Invalid rate limit store.');
                        }
                    }
                    $timestamps = array_values(array_filter($timestamps, static fn (int $value): bool => $value > $now - self::WINDOW_SECONDS));
                    if ($timestamps === []) {
                        unset($data[$kind][$key]);
                    } else {
                        $data[$kind][$key] = $timestamps;
                    }
                }
            }
            $ipAttempts = $data['ip'][$ipKey] ?? [];
            $pairAttempts = $data['pair'][$pairKey] ?? [];
            $retry = 0;
            if (count($ipAttempts) >= 30) {
                $retry = max($retry, min($ipAttempts) + self::WINDOW_SECONDS - $now);
            }
            if (count($pairAttempts) >= 5) {
                $retry = max($retry, min($pairAttempts) + self::WINDOW_SECONDS - $now);
            }
            if ($retry > 0) {
                return $retry;
            }
            $data['ip'][$ipKey][] = $now;
            $data['pair'][$pairKey][] = $now;
            return 0;
        });
        if ($retry > 0) {
            throw new HttpException(429, 'Too many login attempts.', [], ['Retry-After' => (string) $retry]);
        }
    }
}
