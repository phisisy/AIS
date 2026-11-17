<?php
declare(strict_types=1);

namespace App\Auth\Controllers;

use App\Auth\Models\User;
use App\Auth\Services\UserService;
use App\Core\Request;
use App\Core\Response;
use Closure;

/** /api/admin/users*. Every route is wrapped by RouteGuard (admin + CSRF on writes). */
final class UserController
{
    private Closure $clientIp;

    public function __construct(private readonly UserService $users, callable $clientIp)
    {
        $this->clientIp = Closure::fromCallable($clientIp);
    }

    public function index(Request $request, array $params, User $actor): Response
    {
        return Response::success($this->users->list($actor, $request->query));
    }

    public function show(Request $request, array $params, User $actor): Response
    {
        return Response::success(['user' => $this->users->get($actor, $params['id'])]);
    }

    public function create(Request $request, array $params, User $actor): Response
    {
        $input = $request->json(['email', 'password', 'roles', 'status']);
        return Response::success(['user' => $this->users->create($actor, $input)], 'Account created.', 201);
    }

    public function update(Request $request, array $params, User $actor): Response
    {
        $input = $request->json(['email', 'roles']);
        return Response::success(['user' => $this->users->update($actor, $params['id'], $input)], 'Account updated.');
    }

    public function status(Request $request, array $params, User $actor): Response
    {
        $input = $request->json(['status']);
        return Response::success(['user' => $this->users->changeStatus($actor, $params['id'], $input)], 'Account status updated.');
    }

    public function resetPassword(Request $request, array $params, User $actor): Response
    {
        $input = $request->json(['password', 'current_password']);
        $user = $this->users->resetPassword($actor, $params['id'], $input, ($this->clientIp)());
        return Response::success(['user' => $user], 'Password reset. The user\'s existing sessions were signed out.');
    }
}
