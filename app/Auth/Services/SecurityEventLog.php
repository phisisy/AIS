<?php
declare(strict_types=1);

namespace App\Auth\Services;

use App\Core\JsonStore;
use Closure;

/**
 * Security events for refused access to protected routes (T-04). Separate from the
 * administrative audit log. Entries contain only server-generated values (timestamp,
 * a route-id constant, an event-type enum, an integer user id), so no client input can
 * reach the log. record() never throws.
 *
 * Flood resistance: repeats of the same (type, route, actor) within MERGE_SECONDS increase
 * `count` instead of adding entries; anonymous and signed-in events have separate caps so
 * anonymous traffic cannot evict events that identify a user; writers never wait for the
 * lock (a busy store drops the event rather than queueing the request).
 */
final class SecurityEventLog
{
    public const TYPES = ['unauthenticated', 'forbidden_role', 'csrf_failed'];
    public const MAX_ANONYMOUS = 500;
    public const MAX_IDENTIFIED = 1000;
    public const MERGE_SECONDS = 60;
    public const RETENTION_SECONDS = 30 * 86400;
    /** e.g. "PATCH /api/admin/users/{id}/status": route patterns are fixed at registration. */
    public const ROUTE_PATTERN = '~\A(GET|POST|PATCH|PUT|DELETE) /[a-z0-9/_{}-]{1,120}\z~';

    private Closure $clock;

    public function __construct(private readonly JsonStore $store, private readonly string $path, ?callable $clock = null)
    {
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }

    public static function validRoute(string $route): bool
    {
        return preg_match(self::ROUTE_PATTERN, $route) === 1;
    }

    /** Best effort: any failure (bad input, busy or broken storage) is swallowed so callers keep their own outcome. */
    public function record(string $type, string $route, ?int $actorId): void
    {
        if (!in_array($type, self::TYPES, true) || !self::validRoute($route) || ($actorId !== null && $actorId < 1)) {
            return;
        }
        try {
            $now = ($this->clock)();
            $this->store->transaction(static function (array &$data) use ($type, $route, $actorId, $now): void {
                $events = array_values(array_filter(
                    is_array($data['events'] ?? null) ? $data['events'] : [],
                    static fn (mixed $e): bool => is_array($e) && is_int($e['ts'] ?? null) && is_int($e['last_ts'] ?? null)
                        && is_int($e['count'] ?? null) && $e['last_ts'] > $now - self::RETENTION_SECONDS
                ));
                $merged = false;
                for ($i = count($events) - 1; $i >= 0; $i--) {
                    $e = $events[$i];
                    if ($e['type'] === $type && $e['route'] === $route && $e['actor_id'] === $actorId
                        && $e['last_ts'] > $now - self::MERGE_SECONDS) {
                        $events[$i]['count']++;
                        $events[$i]['last_ts'] = $now;
                        $events[$i]['last_at'] = gmdate('Y-m-d\TH:i:s\Z', $now);
                        $merged = true;
                        break;
                    }
                }
                if (!$merged) {
                    $at = gmdate('Y-m-d\TH:i:s\Z', $now);
                    $events[] = ['ts' => $now, 'at' => $at, 'last_ts' => $now, 'last_at' => $at, 'count' => 1,
                        'type' => $type, 'route' => $route, 'actor_id' => $actorId, 'outcome' => 'refused'];
                }
                $data = ['events' => self::bounded($events)];
            }, false);
        } catch (\Throwable $exception) {
            // Logging must never change an authorization decision.
            if ($exception->getMessage() === 'Invalid store data.') {
                $this->quarantine($now ?? time());
            }
        }
    }

    /** @return list<array> oldest first. A corrupt store reads as empty. */
    public function events(): array
    {
        try {
            $events = $this->store->read()['events'] ?? [];
            return is_array($events) ? $events : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Keep the newest MAX_ANONYMOUS anonymous and MAX_IDENTIFIED signed-in events, preserving order. */
    private static function bounded(array $events): array
    {
        $keep = [];
        $anonymous = 0;
        $identified = 0;
        for ($i = count($events) - 1; $i >= 0; $i--) {
            $isAnonymous = $events[$i]['actor_id'] === null;
            if ($isAnonymous ? ++$anonymous > self::MAX_ANONYMOUS : ++$identified > self::MAX_IDENTIFIED) {
                continue;
            }
            $keep[] = $events[$i];
        }
        return array_reverse($keep);
    }

    /** Move an unreadable event file aside so logging resumes; the bad copy is kept for inspection. */
    private function quarantine(int $now): void
    {
        if (is_file($this->path) && @rename($this->path, $this->path . '.corrupt-' . $now)) {
            error_log('AIS security event log was corrupt and has been reset.');
        }
    }
}
