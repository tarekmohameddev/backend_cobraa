<?php

declare(strict_types=1);

namespace Modules\Stox\Http\Controllers\Dashboard\Admin;

use App\Helpers\ResponseError;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Stox\Http\Requests\StoxEmployeeExportReportRequest;
use Modules\Stox\Http\Resources\StoxEmployeeExportCountResource;
use Modules\Stox\Repositories\StoxReportRepository;

class StoxReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StoxReportRepository $repository)
    {
    }

    public function employeeExportCounts(StoxEmployeeExportReportRequest $request): JsonResponse
    {
        $data = $this->repository->getEmployeeExportCounts($request->validated());

        return $this->successResponse(
            __('errors.' . ResponseError::NO_ERROR),
            StoxEmployeeExportCountResource::collection($data)
        );
    }
}
