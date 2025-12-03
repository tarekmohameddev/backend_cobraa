<?php

declare(strict_types=1);

namespace Modules\Stox\Http\Controllers\Dashboard\Admin;

use App\Helpers\ResponseError;
use App\Models\Order;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Stox\Entities\StoxAccount;
use Modules\Stox\Entities\StoxOrder;
use Modules\Stox\Http\Requests\StoxOrderExportRequest;
use Modules\Stox\Http\Resources\StoxOrderResource;
use Modules\Stox\Repositories\StoxOrderRepository;
use Modules\Stox\Services\StoxOrderExportService;
use Illuminate\Support\Facades\Log;
class StoxOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly StoxOrderRepository $repository,
        private readonly StoxOrderExportService $exportService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $orders = $this->repository->paginate($request->all());

        return $this->successResponse(
            __('errors.' . ResponseError::NO_ERROR),
            StoxOrderResource::collection($orders)->response()->getData(true)
        );
    }

    public function show(int $stoxOrderId): JsonResponse
    {
        $stoxOrder = StoxOrder::query()->findOrFail($stoxOrderId);
        $stoxOrder->loadMissing(['account', 'order.user']);

        return $this->successResponse(
            __('errors.' . ResponseError::NO_ERROR),
            StoxOrderResource::make($stoxOrder)
        );
    }

    public function export(StoxOrderExportRequest $request, int $orderId): JsonResponse
    {
        $data = $request->validated();
        $order = Order::query()->findOrFail($orderId);

        // Ensure order has a concrete city/area (or override provides one) before sending to Stox.
        $address = is_array($order->address) ? $order->address : [];
        $overrideData = $data['override_data'] ?? [];

        $effectiveAreaId = $overrideData['area_id'] ?? ($address['area_id'] ?? null);

        if (empty($effectiveAreaId)) {
            return $this->errorResponse(
                ResponseError::ERROR_400,
                'Please select a city/area for this order before sending it to Stox.',
                400
            );
        }

        /** @var StoxAccount $account */
        $account = StoxAccount::query()->findOrFail($data['stox_account_id']);
        $result = $this->exportService->export(
            $order,
            $account,
            $data['override_data'] ?? [],
            $request->user(),
            'manual'
        );

        if (!$result['success']) {
            return $this->errorResponse(ResponseError::ERROR_400, $result['message'], 400);
        }

        $resourceData = $result['data'];
        if ($resourceData instanceof StoxOrder) {
            $resourceData->loadMissing(['account', 'order.user']);
        }

        return $this->successResponse($result['message'], StoxOrderResource::make($resourceData));
    }

    public function retry(int $stoxOrderId): JsonResponse
    {
        $stoxOrder = StoxOrder::query()->findOrFail($stoxOrderId);
        $stoxOrder->loadMissing(['account', 'order']);

        if (!$stoxOrder->account || !$stoxOrder->order) {
            return $this->errorResponse(ResponseError::ERROR_404, 'Stox account or order not found.', 404);
        }

        $result = $this->exportService->export(
            $stoxOrder->order,
            $stoxOrder->account,
            $stoxOrder->export_payload ?? [],
            request()->user(),
            'manual'
        );

        if (!$result['success']) {
            return $this->errorResponse(ResponseError::ERROR_400, $result['message'], 400);
        }

        $resourceData = $result['data'];
        if ($resourceData instanceof StoxOrder) {
            $resourceData->loadMissing(['account', 'order.user']);
        }

        return $this->successResponse($result['message'], StoxOrderResource::make($resourceData));
    }

    public function checkExportStatus(int $orderId): JsonResponse
    {
        $stoxOrder = StoxOrder::query()
            ->where('order_id', $orderId)
            ->orderByDesc('id')
            ->first();

        $data = [
            'order_id' => $orderId,
            'has_stox_order' => (bool) $stoxOrder,
            'export_status' => $stoxOrder?->export_status,
            'stox_order_id' => $stoxOrder?->id,
            'external_order_id' => $stoxOrder?->external_order_id,
            'awb_number' => $stoxOrder?->awb_number,
            'last_error' => $stoxOrder?->last_error,
            'exported_at' => $stoxOrder?->exported_at,
        ];

        return $this->successResponse(
            __('errors.' . ResponseError::NO_ERROR),
            $data
        );
    }
}

