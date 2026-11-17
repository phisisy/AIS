<?php
declare(strict_types=1);

namespace App\Students\Controllers;

use App\Auth\Models\User;
use App\Core\Request;
use App\Core\Response;
use App\Students\Services\StudentService;

/** /api/students*. Every route is wrapped by RouteGuard; rules live in StudentService. */
final class StudentController
{
    private const CREATE_FIELDS = ['user_id', 'student_number', 'first_name', 'middle_name', 'last_name',
        'program_code', 'year_level', 'academic_status', 'admission_year'];
    private const UPDATE_FIELDS = ['first_name', 'middle_name', 'last_name', 'program_code', 'year_level',
        'academic_status', 'admission_year'];

    public function __construct(private readonly StudentService $students)
    {
    }

    public function index(Request $request, array $params, User $actor): Response
    {
        return Response::success($this->students->list($actor, $request->query));
    }

    public function me(Request $request, array $params, User $actor): Response
    {
        return Response::success(['student' => $this->students->me($actor)]);
    }

    public function show(Request $request, array $params, User $actor): Response
    {
        return Response::success(['student' => $this->students->get($actor, $params['id'])]);
    }

    public function create(Request $request, array $params, User $actor): Response
    {
        $student = $this->students->create($actor, $request->json(self::CREATE_FIELDS));
        return Response::success(['student' => $student], 'Student profile created.', 201);
    }

    public function update(Request $request, array $params, User $actor): Response
    {
        $student = $this->students->update($actor, $params['id'], $request->json(self::UPDATE_FIELDS));
        return Response::success(['student' => $student], 'Student profile updated.');
    }
}
