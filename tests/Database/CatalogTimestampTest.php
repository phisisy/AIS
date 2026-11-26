<?php
declare(strict_types=1);

use App\Enrollment\Repositories\MySQLCatalogRepository;

test('T-60 V9 regression (catalog): stored DATETIME values must round-trip; zero and impossible dates fail closed', static function (): void {
    foreach ([MySQLCatalogRepository::class] as $class) {
        $iso = new ReflectionMethod($class, 'isoTime');
        foreach (['2026-11-25 08:40:56' => '2026-11-25T08:40:56Z', '2024-02-29 23:59:59' => '2024-02-29T23:59:59Z',
            '1000-01-01 00:00:00' => '1000-01-01T00:00:00Z'] as $stored => $expected) {
            same($expected, $iso->invoke(null, $stored));
        }
        foreach (['0000-00-00 00:00:00', '2026-00-10 00:00:00', '2026-02-30 00:00:00', '2025-02-29 00:00:00',
            '2026-01-01 24:00:00', '2026-01-01 00:60:00', '0000-01-01 00:00:00', '2026-01-01T00:00:00', ''] as $stored) {
            $refused = false;
            try { $iso->invoke(null, $stored); } catch (RuntimeException $exception) {
                $refused = true;
                check($exception->getPrevious() === null, 'Timestamp refusal must not chain.');
            }
            check($refused, $class . ' accepted invalid stored timestamp ' . $stored);
        }
    }
});
