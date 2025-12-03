<?php

namespace Modules\OrderLocking\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\OrderLocking\Services\OrderLockService;

class OrderLockController extends Controller
{
    protected OrderLockService $lockService;

    public function __construct(OrderLockService $lockService)
    {
        $this->lockService = $lockService;
    }

    /**
     * Heartbeat endpoint to refresh lock expiration
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function heartbeat(Request $request, int $id): JsonResponse
    {
        $userId = auth('sanctum')->id();

        $refreshed = $this->lockService->refreshLock($id, $userId);

        if ($refreshed) {
            return response()->json([
                'status' => 'success',
                'message' => 'Lock refreshed successfully',
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Failed to refresh lock. You may not have an active lock on this order.',
        ], 400);
    }

    /**
     * Explicitly unlock an order
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function unlock(Request $request, int $id): JsonResponse
    {
        $userId = auth('sanctum')->id();

        $unlocked = $this->lockService->unlockOrder($id, $userId);

        if ($unlocked) {
            return response()->json([
                'status' => 'success',
                'message' => 'Order unlocked successfully',
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'No active lock found',
        ]);
    }
}
