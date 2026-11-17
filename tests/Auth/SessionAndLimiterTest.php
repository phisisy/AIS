<?php
declare(strict_types=1);

use App\Auth\Services\LoginRateLimiter;
use App\Core\HttpException;
use App\Core\JsonStore;

test('Session expires after 30 minutes idle and after 8 hours even when active', function (): void {
    foreach (['idle', 'absolute'] as $mode) {
        $directory = tempDirectory();
        try {
            [$exit, $stdout, $stderr] = runProcess([PHP_BINARY, __DIR__ . '/fixtures/session_clock.php', $directory, $mode]);
            same(0, $exit);
            same('', $stderr);
            $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            $identity = ['user_id' => 7, 'auth_version' => 3];
            same($identity, $result['fresh']);
            same($identity, $result['before']);
            same(null, $result['after']);
            same(null, $result['after_again']);
        } finally {
            removeTestDirectory($directory);
        }
    }
});

test('LoginRateLimiter blocks the 31st attempt per IP across emails and resets after the window', function (): void {
    $directory = tempDirectory();
    try {
        $now = 5_000;
        $limiter = new LoginRateLimiter(new JsonStore($directory . '/attempts.json'), static function () use (&$now): int { return $now; });
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $limiter->consume('192.0.2.10', "user{$attempt}@example.test");
        }
        $blocked = throws(HttpException::class, fn () => $limiter->consume('192.0.2.10', 'another@example.test'));
        same(429, $blocked->status);
        same('900', $blocked->headers['Retry-After']);
        // A different IP is independent.
        $limiter->consume('192.0.2.11', 'another@example.test');
        // Denied attempts do not extend the window.
        $now += 899;
        same('1', throws(HttpException::class, fn () => $limiter->consume('192.0.2.10', 'x@example.test'))->headers['Retry-After']);
        $now += 1;
        $limiter->consume('192.0.2.10', 'x@example.test');
    } finally {
        removeTestDirectory($directory);
    }
});

test('LoginRateLimiter fails closed on an invalid client address', function (): void {
    $directory = tempDirectory();
    try {
        $limiter = new LoginRateLimiter(new JsonStore($directory . '/attempts.json'));
        throws(RuntimeException::class, fn () => $limiter->consume('', 'a@example.test'));
        throws(RuntimeException::class, fn () => $limiter->consume('not-an-ip', 'a@example.test'));
    } finally {
        removeTestDirectory($directory);
    }
});
