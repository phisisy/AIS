<?php
declare(strict_types=1);

namespace App\Enrollment\Controllers;

use App\Auth\Models\User;
use App\Core\Request;
use App\Core\Response;
use App\Enrollment\Services\SectionService;

final class SectionController
{
    private const CREATE_FIELDS = ['term_id', 'course_code', 'course_title', 'units', 'section_code', 'capacity', 'delivery_mode', 'meetings'];
    private const UPDATE_FIELDS = ['course_title', 'units', 'capacity', 'delivery_mode', 'meetings'];

    public function __construct(private readonly SectionService $sections)
    {
    }

    public function index(Request $request, array $params, User $actor): Response
    {
        return Response::success($this->sections->list($request->query));
    }

    public function show(Request $request, array $params, User $actor): Response
    {
        return Response::success(['section' => $this->sections->get($params['id'])]);
    }

    public function create(Request $request, array $params, User $actor): Response
    {
        $section = $this->sections->create($actor, $request->json(self::CREATE_FIELDS));
        return Response::success(['section' => $section], 'Section created.', 201);
    }

    public function update(Request $request, array $params, User $actor): Response
    {
        $section = $this->sections->update($actor, $params['id'], $request->json(self::UPDATE_FIELDS));
        return Response::success(['section' => $section], 'Section updated.');
    }
}
