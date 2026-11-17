<?php
declare(strict_types=1);

namespace App\Auth\Middleware;

use App\Auth\Services\AuthorizationService;
use App\Auth\Services\AuthService;
use App\Auth\SessionInterface;
use App\Core\Request;
use App\Core\Response;
use Closure;

/**
 * Wraps a route handler: authenticate (401) → role (403) → CSRF on state-changing
 * methods (403). The wrapped handler receives (Request, array $params, User $user),
 * with the user freshly reloaded for this request.
 */
final class RouteGuard
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly AuthService $auth,
        private readonly AuthorizationService $authorization,
        private readonly SessionInterface $session,
    ) {
    }

    /** Any signed-in, active user. */
    public function authenticated(callable $handler): GuardedHandler
    {
        return $this->wrap(null, $handler);
    }

    /** Signed-in user holding at least one of $roles. */
    public function roles(array $roles, callable $handler): GuardedHandler
    {
        return $this->wrap(AuthorizationService::validAllowlist($roles), $handler);
    }

    private function wrap(?array $roles, callable $handler): GuardedHandler
    {
        $handler = Closure::fromCallable($handler);
        return new GuardedHandler(function (Request $request, array $params) use ($roles, $handler): Response {
            $user = $this->auth->requireUser();
            if ($roles !== null) {
                $this->authorization->requireRole($user, $roles);
            }
            if (!in_array($request->method, self::SAFE_METHODS, true)) {
                $this->session->assertCsrf($request->header('X-CSRF-Token'));
            }
            return $handler($request, $params, $user);
        });
    }
}
