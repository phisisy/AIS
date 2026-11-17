<?php
declare(strict_types=1);

namespace App\Auth\Middleware;

use App\Auth\Services\AuthorizationService;
use App\Auth\Services\SecurityEventLog;
use App\Core\HttpException;
use InvalidArgumentException;
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
        private readonly ?SecurityEventLog $events = null,
    ) {
    }

    /** Any signed-in, active user. */
    public function authenticated(callable $handler): GuardedHandler
    {
        return $this->wrap(null, $handler, null);
    }

    /**
     * Signed-in user holding at least one of $roles. With $securityRoute (e.g. "GET /api/admin/users"),
     * each refusal on this route is also written to the security event log (T-04).
     */
    public function roles(array $roles, callable $handler, ?string $securityRoute = null): GuardedHandler
    {
        if ($securityRoute !== null && ($this->events === null || !SecurityEventLog::validRoute($securityRoute))) {
            throw new InvalidArgumentException('Invalid security-event route.');
        }
        return $this->wrap(AuthorizationService::validAllowlist($roles), $handler, $securityRoute);
    }

    private function wrap(?array $roles, callable $handler, ?string $securityRoute): GuardedHandler
    {
        $handler = Closure::fromCallable($handler);
        return new GuardedHandler(function (Request $request, array $params) use ($roles, $handler, $securityRoute): Response {
            $user = null;
            $stage = 'unauthenticated';
            try {
                $user = $this->auth->requireUser();
                $stage = 'forbidden_role';
                if ($roles !== null) {
                    $this->authorization->requireRole($user, $roles);
                }
                $stage = 'csrf_failed';
                if (!in_array($request->method, self::SAFE_METHODS, true)) {
                    $this->session->assertCsrf($request->header('X-CSRF-Token'));
                }
            } catch (HttpException $refusal) {
                // One event per refused request; record() never throws, so the refusal always stands.
                if ($securityRoute !== null) {
                    $this->events?->record($stage, $securityRoute, $user?->id);
                }
                throw $refusal;
            }
            // Outside the try: errors raised by the handler (e.g. service audit denials) are not security events.
            return $handler($request, $params, $user);
        });
    }
}
