<?php

namespace Modules\OrderLocking\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\OrderLocking\Services\OrderLockService;

class OrderLockMiddleware
{
    protected OrderLockService $lockService;

    public function __construct(OrderLockService $lockService)
    {
        $this->lockService = $lockService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Get order ID from route parameter
        $orderId = $request->route('order') ?? $request->route('id');

        // Skip if no order ID (e.g., index/paginate routes)
        if (!$orderId) {
            return $next($request);
        }

        // User should be authenticated by sanctum middleware at this point
        // IMPORTANT: Use 'sanctum' guard explicitly to match SanctumCheck middleware
        $userId = auth('sanctum')->id();
        
        \Log::info('OrderLockMiddleware: Checking auth state', [
            'user_id' => $userId,
            'auth_check' => auth('sanctum')->check(),
            'auth_user' => auth('sanctum')->user() ? auth('sanctum')->user()->id : null,
            'order_id' => $orderId,
        ]);
        
        // If user is not authenticated yet, skip locking
        if (!$userId) {
            \Log::warning('OrderLockMiddleware: Skipping - no authenticated user');
            return $next($request);
        }
        
        $userName = auth('sanctum')->user()->name ?? 'Unknown User';

        // Check if order is locked
        $existingLock = $this->lockService->isOrderLocked($orderId);

        if ($existingLock) {
            // If locked by another user, return 423 Locked status
            if ($existingLock['user_id'] != $userId) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('Order is currently being viewed or edited by another user'),
                    'data' => [
                        'locked_by' => $existingLock['user_name'],
                        'locked_at' => $existingLock['locked_at'],
                        'expires_at' => $existingLock['expires_at'],
                    ]
                ], 423); // HTTP 423 Locked
            }
        }

        // Lock the order for current user (or refresh if already locked by them)
        $lockResult = $this->lockService->lockOrder($orderId, $userId, $userName);
        
        if (!$lockResult) {
            \Log::error('Failed to create order lock', [
                'order_id' => $orderId,
                'user_id' => $userId,
                'user_name' => $userName
            ]);
        }

        return $next($request);
    }
}
