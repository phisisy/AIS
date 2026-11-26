<?php
declare(strict_types=1);

namespace App\Faculty\Controllers;

use App\Auth\Models\User;
use App\Core\Request;
use App\Core\Response;
use App\Faculty\Services\GradeService;

/** Grade routes (faculty, registrar/admin, student), each wrapped by RouteGuard; rules live in GradeService. */
final class GradeController
{
    public function __construct(private readonly GradeService $grades)
    {
    }

    public function classGrades(Request $request, array $params, User $actor): Response
    {
        return Response::success($this->grades->classGrades($actor, $params['id']));
    }

    public function enter(Request $request, array $params, User $actor): Response
    {
        $result = $this->grades->enter($actor, $params['id'], $params['studentId'], $request->json(['value', 'remarks']));
        return $result['created']
            ? Response::success(['grade' => $result['grade']], 'Grade entered.', 201)
            : Response::success(['grade' => $result['grade']], 'Grade updated.');
    }

    public function submit(Request $request, array $params, User $actor): Response
    {
        $request->json([]);
        return Response::success($this->grades->submit($actor, $params['id']), 'Grades submitted.');
    }

    public function sectionGrades(Request $request, array $params, User $actor): Response
    {
        return Response::success($this->grades->sectionGrades($actor, $params['id']));
    }

    public function finalize(Request $request, array $params, User $actor): Response
    {
        $request->json([]);
        return Response::success($this->grades->finalize($actor, $params['id']), 'Grades finalized.');
    }

    public function returnToDraft(Request $request, array $params, User $actor): Response
    {
        return Response::success($this->grades->returnToDraft($actor, $params['id'], $request->json(['reason'])), 'Grades returned to draft.');
    }

    public function correct(Request $request, array $params, User $actor): Response
    {
        return Response::success($this->grades->correct($actor, $params['id'], $request->json(['value', 'reason', 'expected_seq'])), 'Grade corrected.');
    }

    public function mine(Request $request, array $params, User $actor): Response
    {
        return Response::success($this->grades->mine($actor, $request->query));
    }
}
