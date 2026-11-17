<?php
declare(strict_types=1);

namespace App\Enrollment\Controllers;

use App\Auth\Models\User;
use App\Core\Request;
use App\Core\Response;
use App\Enrollment\Services\TermService;

final class TermController
{
    private const CREATE_FIELDS = ['code', 'name', 'start_date', 'end_date', 'is_current', 'enrollment_open'];
    private const UPDATE_FIELDS = ['name', 'start_date', 'end_date', 'is_current', 'enrollment_open'];

    public function __construct(private readonly TermService $terms)
    {
    }

    public function index(Request $request, array $params, User $actor): Response
    {
        return Response::success($this->terms->list());
    }

    public function create(Request $request, array $params, User $actor): Response
    {
        $term = $this->terms->create($actor, $request->json(self::CREATE_FIELDS));
        return Response::success(['term' => $term], 'Term created.', 201);
    }

    public function update(Request $request, array $params, User $actor): Response
    {
        $term = $this->terms->update($actor, $params['id'], $request->json(self::UPDATE_FIELDS));
        return Response::success(['term' => $term], 'Term updated.');
    }
}
