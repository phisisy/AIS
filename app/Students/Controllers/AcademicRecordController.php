<?php
declare(strict_types=1);

namespace App\Students\Controllers;

use App\Auth\Models\User;
use App\Core\Request;
use App\Core\Response;
use App\Students\Services\AcademicRecordService;

/** T-11a: academic records and the read-only program catalog. Rules live in AcademicRecordService. */
final class AcademicRecordController
{
    public function __construct(private readonly AcademicRecordService $records)
    {
    }

    public function show(Request $request, array $params, User $actor): Response
    {
        return Response::success(['academic_record' => $this->records->forStudent($actor, $params['id'], $request->query)]);
    }

    public function me(Request $request, array $params, User $actor): Response
    {
        return Response::success(['academic_record' => $this->records->forMe($actor, $request->query)]);
    }

    public function programs(Request $request, array $params, User $actor): Response
    {
        return Response::success($this->records->programs($actor));
    }
}
