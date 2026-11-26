<?php
declare(strict_types=1);

namespace App\Auth\Controllers;

use App\Auth\Services\AuthService;
use App\Auth\SessionInterface;
use App\Core\Request;
use App\Core\Response;
use Closure;

final class AuthController
{
    private Closure $clientIp;

    public function __construct(
        private readonly AuthService $auth,
        private readonly SessionInterface $session,
        callable $clientIp,
    ) {
        $this->clientIp = Closure::fromCallable($clientIp);
    }

    public function csrf(Request $request): Response
    {
        return Response::success(['csrf_token' => $this->session->csrfToken()]);
    }

    public function login(Request $request): Response
    {
        $this->session->assertCsrf($request->header('X-CSRF-Token'));
        $input = $request->json(['email', 'password']);
        $user = $this->auth->login($input['email'] ?? null, $input['password'] ?? null, ($this->clientIp)());
        // Login rotated the session, so the client must replace its token.
        return Response::success(
            ['user' => $user->toPublicArray(), 'csrf_token' => $this->session->csrfToken()],
            'Signed in successfully.'
        );
    }

    public function me(Request $request): Response
    {
        return Response::success(['user' => $this->auth->requireUser()->toPublicArray()]);
    }

    public function logout(Request $request): Response
    {
        $this->auth->requireUser();
        $this->session->assertCsrf($request->header('X-CSRF-Token'));
        $this->auth->logout();
        return Response::success([], 'Signed out successfully.');
    }
}
