<?php
declare(strict_types=1);

use App\Core\JsonStore;

require_once __DIR__ . '/HttpSupport.php';

test('auth HTTP csrf login me logout flow rotates identity and protects response fields', function (): void {
    $server = new AuthHttpServer();
    try {
        same(401, $server->request('GET', '/api/auth/me')['status']);
        $csrfResponse = $server->request('GET', '/api/auth/csrf');
        same(200, $csrfResponse['status']);
        $csrf = $csrfResponse['json']['data']['csrf_token'];
        $anonymousCookie = $server->cookie();
        check($anonymousCookie !== '', 'Anonymous session cookie missing.');
        // The anonymous /me response may already have started the cookie.
        $server->setCookie('');
        $newAnonymous = $server->request('GET', '/api/auth/csrf');
        $cookieHeader = implode(';', $newAnonymous['set_cookies']);
        check(str_contains(strtolower($cookieHeader), 'httponly'), 'Session cookie must be HttpOnly.');
        check(str_contains(strtolower($cookieHeader), 'samesite=lax'), 'Session cookie must use SameSite=Lax.');
        $csrf = $newAnonymous['json']['data']['csrf_token'];
        $anonymousCookie = $server->cookie();
        $login = $server->request('POST', '/api/auth/login', ['email' => '  STUDENT@EXAMPLE.TEST  ', 'password' => 'Synthetic-test-pass-42'], $csrf);
        same(200, $login['status']);
        check($server->cookie() !== $anonymousCookie, 'Login must regenerate the session ID.');
        $authenticatedCookie = $server->cookie();
        $newCsrf = $login['json']['data']['csrf_token'];
        check($newCsrf !== $csrf, 'Login must rotate CSRF token.');
        same(['id' => 1, 'email' => 'student@example.test', 'roles' => ['student'], 'status' => 'active'], $login['json']['data']['user']);
        $me = $server->request('GET', '/api/auth/me');
        same(200, $me['status']);
        same($login['json']['data']['user'], $me['json']['data']['user']);
        foreach ([$login, $me] as $response) {
            same('no-store', $response['headers']['cache-control']);
            foreach (['password_hash', 'auth_version', 'Synthetic-test-pass-42', substr($authenticatedCookie, strlen('AIS_SESSION='))] as $private) {
                check(!str_contains($response['body'], $private), 'Authentication JSON exposed private data.');
            }
        }
        same(403, $server->request('POST', '/api/auth/logout', [], $csrf)['status']);
        same(200, $server->request('GET', '/api/auth/me')['status']);
        $logout = $server->request('POST', '/api/auth/logout', [], $newCsrf);
        same(200, $logout['status']);
        same(true, $logout['json']['success']);
        same(401, $server->request('GET', '/api/auth/me')['status']);
        $server->setCookie($authenticatedCookie);
        same(401, $server->request('GET', '/api/auth/me')['status']);
        $server->setCookie($anonymousCookie);
        same(401, $server->request('GET', '/api/auth/me')['status']);
    } finally {
        $server->close();
    }
});

test('auth HTTP rejects missing invalid CSRF and unknown login fields', function (): void {
    $server = new AuthHttpServer();
    try {
        $credentials = ['email' => 'student@example.test', 'password' => 'Synthetic-test-pass-42'];
        same(403, $server->request('POST', '/api/auth/login', $credentials)['status']);
        $token = $server->csrf();
        same(403, $server->request('POST', '/api/auth/login', $credentials, 'invalid')['status']);
        same(403, $server->request('POST', '/api/auth/login', $credentials, str_repeat('0', strlen($token)))['status']);
        same(422, $server->request('POST', '/api/auth/login', $credentials + ['role' => 'admin'], $token)['status']);
        same(401, $server->request('GET', '/api/auth/me')['status']);
        $login = $server->request('POST', '/api/auth/login', $credentials, $token);
        same(200, $login['status']);
        same(403, $server->request('POST', '/api/auth/logout', [])['status']);
        same(200, $server->request('GET', '/api/auth/me')['status']);
    } finally {
        $server->close();
    }
});

test('auth HTTP returns identical 401 bodies for all invalid credential cases', function (): void {
    $server = new AuthHttpServer();
    try {
        $token = $server->csrf();
        $expected = null;
        $cases = [
            ['email' => 'unknown@example.test', 'password' => 'Synthetic-test-pass-42'],
            ['email' => 'student@example.test', 'password' => 'wrong-password'],
            ['email' => 'inactive@example.test', 'password' => 'Synthetic-test-pass-42'],
            ['email' => ['student@example.test'], 'password' => 'Synthetic-test-pass-42'],
            ['email' => 'student@example.test', 'password' => ['Synthetic-test-pass-42']],
            ['email' => false, 'password' => 42],
            [],
        ];
        foreach ($cases as $credentials) {
            $response = $server->request('POST', '/api/auth/login', $credentials, $token);
            same(401, $response['status']);
            $expected ??= $response['body'];
            same($expected, $response['body']);
            same('Invalid email or password.', $response['json']['message']);
            same(401, $server->request('GET', '/api/auth/me')['status']);
        }
    } finally {
        $server->close();
    }
});

test('auth HTTP reloads users and revokes inactive changed-version and deleted identities', function (): void {
    foreach (['inactive', 'version', 'deleted'] as $change) {
        $server = new AuthHttpServer();
        try {
            same(200, $server->login()['status']);
            // Mock: the same raw store edits as before; MySQL: the same logical changes by tester SQL (user 1 is not referenced).
            if ($change === 'inactive') {
                serverSetUserStatus($server, 1, 'inactive');
            } elseif ($change === 'version') {
                serverBumpAuthVersion($server, 1);
            } else {
                serverDeleteUser($server, 1);
            }
            same(401, $server->request('GET', '/api/auth/me')['status']);
            same(401, $server->request('GET', '/api/auth/me')['status']);
        } finally {
            $server->close();
        }
    }
});

// Raw request() on purpose: AuthHttpServer::login() clears the fixture limiter store, this must not.
test('auth HTTP: successful sign-ins count too, so the sixth correct sign-in of one account is throttled', function (): void {
    $server = new AuthHttpServer();
    try {
        $credentials = ['email' => 'student@example.test', 'password' => 'Synthetic-test-pass-42'];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $server->setCookie('');
            same(200, $server->request('POST', '/api/auth/login', $credentials, $server->csrf())['status']);
        }
        $server->setCookie('');
        $blocked = $server->request('POST', '/api/auth/login', $credentials, $server->csrf());
        same([429, false], [$blocked['status'], $blocked['json']['success']]);
        check(ctype_digit($blocked['headers']['retry-after'] ?? ''), '429 must provide Retry-After.');
        same(401, $server->request('GET', '/api/auth/me')['status']); // a throttled correct password never signs in
    } finally {
        $server->close();
    }
});

test('auth HTTP throttles the sixth pair attempt across sessions with Retry-After', function (): void {
    $server = new AuthHttpServer();
    try {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $server->setCookie('');
            $token = $server->csrf();
            same(401, $server->request('POST', '/api/auth/login', ['email' => 'unknown@example.test', 'password' => 'wrong'], $token)['status']);
        }
        $server->setCookie('');
        $token = $server->csrf();
        $blocked = $server->request('POST', '/api/auth/login', ['email' => ' UNKNOWN@EXAMPLE.TEST ', 'password' => 'wrong'], $token, ['X-Forwarded-For' => '192.0.2.7']);
        same(429, $blocked['status']);
        check(ctype_digit($blocked['headers']['retry-after'] ?? ''), '429 must provide Retry-After.');
        check((int) $blocked['headers']['retry-after'] > 0 && (int) $blocked['headers']['retry-after'] <= 900);
        same(false, $blocked['json']['success']);
    } finally {
        $server->close();
    }
});
