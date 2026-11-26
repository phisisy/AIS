<?php
declare(strict_types=1);

namespace App\Faculty\Controllers;

use App\Auth\Models\User;
use App\Core\Request;
use App\Core\Response;
use App\Faculty\Services\FacultyService;

/** /api/registrar/faculty* and /api/faculty/{me,classes}. Every route is wrapped by RouteGuard; rules live in FacultyService. */
final class FacultyController
{
    private const CREATE_FIELDS = ['user_id', 'employee_number', 'first_name', 'last_name', 'department', 'status'];
    private const UPDATE_FIELDS = ['first_name', 'last_name', 'department', 'status'];

    public function __construct(private readonly FacultyService $faculty)
    {
    }

    public function index(Request $request, array $params, User $actor): Response
    {
        return Response::success($this->faculty->list($actor, $request->query));
    }

    public function show(Request $request, array $params, User $actor): Response
    {
        return Response::success(['faculty' => $this->faculty->get($actor, $params['id'])]);
    }

    public function create(Request $request, array $params, User $actor): Response
    {
        $profile = $this->faculty->create($actor, $request->json(self::CREATE_FIELDS));
        return Response::success(['faculty' => $profile], 'Faculty profile created.', 201);
    }

    public function update(Request $request, array $params, User $actor): Response
    {
        $profile = $this->faculty->update($actor, $params['id'], $request->json(self::UPDATE_FIELDS));
        return Response::success(['faculty' => $profile], 'Faculty profile updated.');
    }

    public function me(Request $request, array $params, User $actor): Response
    {
        return Response::success(['faculty' => $this->faculty->me($actor)]);
    }

    public function classes(Request $request, array $params, User $actor): Response
    {
        return Response::success($this->faculty->classes($actor, $request->query));
    }
}
