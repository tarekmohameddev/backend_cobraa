<?php

declare(strict_types=1);

namespace Modules\OrderEnhancements\Http\Controllers\Dashboard\Admin;

use App\Helpers\ResponseError;
use App\Models\Order;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\OrderEnhancements\Http\Requests\CreateOrderUpdateRequest;
use Modules\OrderEnhancements\Http\Resources\OrderUpdateResource;
use Modules\OrderEnhancements\Services\OrderUpdateService;

class OrderUpdateController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OrderUpdateService $service
    ) {}

    public function index(Order $order, Request $request): JsonResponse
    {
        $updates = $this->service->getForOrder($order, $request->all());

        return $this->successResponse(
            __('errors.' . ResponseError::NO_ERROR),
            OrderUpdateResource::collection($updates)
        );
    }

    public function store(Order $order, CreateOrderUpdateRequest $request): JsonResponse
    {
        $user = $request->user();

        $update = $this->service->create($order, $user, $request->validated());

        return $this->successResponse(
            __('errors.' . ResponseError::NO_ERROR),
            OrderUpdateResource::make($update->load('user'))
        );
    }
}
