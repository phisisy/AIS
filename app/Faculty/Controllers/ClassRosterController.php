<?php
declare(strict_types=1);

namespace App\Faculty\Controllers;

use App\Auth\Models\User;
use App\Core\Request;
use App\Core\Response;
use App\Faculty\Services\ClassRosterService;

/** GET /api/faculty/classes/{id}/students, wrapped by RouteGuard; rules live in ClassRosterService. */
final class ClassRosterController
{
    public function __construct(private readonly ClassRosterService $rosters)
    {
    }

    public function students(Request $request, array $params, User $actor): Response
    {
        return Response::success($this->rosters->roster($actor, $params['id'], $request->query));
    }
}
