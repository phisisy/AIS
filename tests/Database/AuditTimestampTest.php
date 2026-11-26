<?php
declare(strict_types=1);

use App\Auth\Repositories\MySQLUserRepository;

// SR-1: offline check of the private static isoTime() round trip.
$isoTime = static fn (string $time): string => (new ReflectionMethod(MySQLUserRepository::class, 'isoTime'))->invoke(null, $time);

test('Audit timestamp: valid stored DATETIME values are converted to ISO', static function () use ($isoTime): void {
    same('2026-11-25T08:40:56Z', $isoTime('2026-11-25 08:40:56'));
    same('2024-02-29T23:59:59Z', $isoTime('2024-02-29 23:59:59'));
    same('1000-01-01T00:00:00Z', $isoTime('1000-01-01 00:00:00'));
});

test('Audit timestamp: invalid values are refused without a previous exception', static function () use ($isoTime): void {
    foreach (['0000-00-00 00:00:00', '2026-00-10 00:00:00', '2026-02-30 00:00:00', '2025-02-29 00:00:00',
        '2026-01-01 24:00:00', '2026-01-01 00:60:00', '0000-01-01 00:00:00', '2026-01-01T00:00:00', ''] as $bad) {
        try {
            $isoTime($bad);
            check(false, "Accepted invalid timestamp '{$bad}'");
        } catch (RuntimeException $error) {
            same('Invalid audit store.', $error->getMessage());
            same(null, $error->getPrevious());
        }
    }
});
