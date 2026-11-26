<?php
declare(strict_types=1);

namespace App\Enrollment\Controllers;

use App\Auth\Models\User;
use App\Core\Request;
use App\Core\Response;
use App\Enrollment\Services\EnrollmentService;

final class EnrollmentController
{
    public function __construct(private readonly EnrollmentService $enrollments)
    {
    }

    public function request(Request $request, array $params, User $actor): Response
    {
        $enrollment = $this->enrollments->request($actor, $request->json(['section_id']));
        return Response::success(['enrollment' => $enrollment], 'Enrollment requested.', 201);
    }

    public function mine(Request $request, array $params, User $actor): Response
    {
        return Response::success($this->enrollments->mine($actor, $request->query));
    }

    public function drop(Request $request, array $params, User $actor): Response
    {
        $request->json([]);
        return Response::success(['enrollment' => $this->enrollments->drop($actor, $params['id'])], 'Enrollment dropped.');
    }

    public function schedule(Request $request, array $params, User $actor): Response
    {
        return Response::success($this->enrollments->schedule($actor, $request->query));
    }

    public function index(Request $request, array $params, User $actor): Response
    {
        return Response::success($this->enrollments->list($actor, $request->query));
    }

    public function decide(Request $request, array $params, User $actor): Response
    {
        $enrollment = $this->enrollments->decide($actor, $params['id'], $request->json(['status', 'reason']));
        return Response::success(['enrollment' => $enrollment], 'Enrollment decided.');
    }
}
