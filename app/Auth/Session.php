<?php
declare(strict_types=1);

namespace App\Auth;

use App\Core\HttpException;
use Closure;
use InvalidArgumentException;
use RuntimeException;

final class Session implements SessionInterface
{
    public const COOKIE_NAME = 'AIS_SESSION';
    public const IDLE_TIMEOUT = 1800;
    public const ABSOLUTE_TIMEOUT = 28800;

    private Closure $clock;
    private ?string $ownedId = null;

    public function __construct(private string $runtimePath, private bool $secure = false, ?callable $clock = null)
    {
        if ($runtimePath === '' || str_contains($runtimePath, "\0") || str_contains($runtimePath, '://')) {
            throw new InvalidArgumentException('Invalid session runtime path.');
        }
        $this->clock = Closure::fromCallable($clock ?? static fn (): int => time());
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->assertOwnership();
            return;
        }
        if (session_status() === PHP_SESSION_DISABLED || headers_sent()) {
            throw new RuntimeException('Unable to start session.');
        }
        $directory = rtrim($this->runtimePath, '/\\') . '/sessions';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create session directory.');
        }
        session_module_name('files');
        session_save_path($directory);
        session_name(self::COOKIE_NAME);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) self::ABSOLUTE_TIMEOUT);
        // Response owns caching headers. Native sessions must not replace them.
        session_cache_limiter('');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new RuntimeException('Unable to start session.');
        }
        $this->ownedId = session_id();
    }

    public function csrfToken(): string
    {
        $this->start();
        $this->validatedIdentity();
        if (!isset($_SESSION['_ais_csrf']) || !is_string($_SESSION['_ais_csrf'])
            || !preg_match('/\A[a-f0-9]{64}\z/', $_SESSION['_ais_csrf'])) {
            $_SESSION['_ais_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_ais_csrf'];
    }

    public function assertCsrf(?string $token): void
    {
        $this->start();
        $this->validatedIdentity();
        $stored = $_SESSION['_ais_csrf'] ?? null;
        if ($token === null || !preg_match('/\A[a-f0-9]{64}\z/', $token)
            || !is_string($stored) || !hash_equals($stored, $token)) {
            throw new HttpException(403, 'Invalid CSRF token.');
        }
    }

    public function login(int $userId, int $authVersion): void
    {
        if ($userId < 1 || $authVersion < 1) {
            throw new InvalidArgumentException('Invalid session identity.');
        }
        $this->start();
        $this->regenerate();
        $now = $this->now();
        $_SESSION = [
            '_ais_auth' => [
                'user_id' => $userId,
                'auth_version' => $authVersion,
                'created_at' => $now,
                'last_seen' => $now,
            ],
            '_ais_csrf' => bin2hex(random_bytes(32)),
        ];
    }

    public function identity(): ?array
    {
        $this->start();
        $identity = $this->validatedIdentity();
        if ($identity !== null) {
            $_SESSION['_ais_auth']['last_seen'] = $this->now();
        }
        return $identity;
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];
        if (!session_destroy()) {
            throw new RuntimeException('Unable to destroy session.');
        }
        $this->ownedId = null;
        session_id('');
        if (!setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 42000,
            'path' => '/',
            'domain' => '',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ])) {
            throw new RuntimeException('Unable to expire session cookie.');
        }
    }

    public function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->assertOwnership();
            if (!session_write_close()) {
                throw new RuntimeException('Unable to save session.');
            }
        }
        $this->ownedId = null;
    }

    private function validatedIdentity(): ?array
    {
        if (!array_key_exists('_ais_auth', $_SESSION)) {
            return null;
        }
        $auth = $_SESSION['_ais_auth'];
        $now = $this->now();
        $valid = is_array($auth);
        foreach (['user_id', 'auth_version', 'created_at', 'last_seen'] as $key) {
            $valid = $valid && isset($auth[$key]) && is_int($auth[$key]);
        }
        if (!$valid || $auth['user_id'] < 1 || $auth['auth_version'] < 1
            || $auth['created_at'] < 0 || $auth['last_seen'] < $auth['created_at']
            || $now < $auth['last_seen'] || $now - $auth['last_seen'] >= self::IDLE_TIMEOUT
            || $now - $auth['created_at'] >= self::ABSOLUTE_TIMEOUT) {
            $this->regenerate();
            $_SESSION = [];
            return null;
        }
        return ['user_id' => $auth['user_id'], 'auth_version' => $auth['auth_version']];
    }

    private function regenerate(): void
    {
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to regenerate session.');
        }
        $this->ownedId = session_id();
    }

    private function assertOwnership(): void
    {
        if ($this->ownedId === null || session_name() !== self::COOKIE_NAME || session_id() !== $this->ownedId) {
            throw new RuntimeException('A different session is already active.');
        }
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0) {
            throw new RuntimeException('Invalid session clock.');
        }
        return $now;
    }
}
