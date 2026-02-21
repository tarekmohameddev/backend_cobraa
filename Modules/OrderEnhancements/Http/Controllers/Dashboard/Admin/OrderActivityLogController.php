<?php
declare(strict_types=1);

namespace Modules\OrderEnhancements\Http\Controllers\Dashboard\Admin;

use App\Helpers\ResponseError;
use App\Models\Order;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\OrderEnhancements\Http\Resources\OrderActivityLogResource;
use Modules\OrderEnhancements\Services\OrderActivityLogQueryService;

class OrderActivityLogController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OrderActivityLogQueryService $service
    ) {}

    public function index(Order $order, Request $request): JsonResponse
    {
        $logs = $this->service->getForOrder($order, $request->all());

        return $this->successResponse(
            __('errors.' . ResponseError::NO_ERROR),
            OrderActivityLogResource::collection($logs)
        );
    }
}

