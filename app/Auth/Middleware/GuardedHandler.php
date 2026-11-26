<?php
declare(strict_types=1);

namespace App\Auth\Middleware;

use App\Core\Request;
use App\Core\Response;
use Closure;

/** Marker type for route handlers produced by RouteGuard, so tests can prove no route is left unguarded. */
final class GuardedHandler
{
    public function __construct(private readonly Closure $handler)
    {
    }

    public function __invoke(Request $request, array $params): Response
    {
        return ($this->handler)($request, $params);
    }
}
