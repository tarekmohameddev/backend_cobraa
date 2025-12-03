<?php

namespace Modules\OrderLocking\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OrderLockService
{
    /**
     * Check if an order is currently locked by another user
     *
     * @param int $orderId
     * @return array|null Returns lock info if locked, null if not locked or expired
     */
    public function isOrderLocked(int $orderId): ?array
    {
        $lock = DB::table('order_locks')
            ->where('order_id', $orderId)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        return $lock ? (array) $lock : null;
    }

    /**
     * Lock an order for the given user
     *
     * @param int $orderId
     * @param int $userId
     * @param string $userName
     * @return bool
     */
    public function lockOrder(int $orderId, int $userId, string $userName): bool
    {
        $ttl = config('orderlocking.lock_ttl', 60);
        $now = Carbon::now();
        $expiresAt = $now->copy()->addSeconds($ttl);

        try {
            DB::table('order_locks')->updateOrInsert(
                ['order_id' => $orderId],
                [
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'locked_at' => $now,
                    'expires_at' => $expiresAt,
                    'updated_at' => $now,
                ]
            );

            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to lock order', [
                'order_id' => $orderId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Refresh the lock expiration time (heartbeat)
     *
     * @param int $orderId
     * @param int $userId
     * @return bool
     */
    public function refreshLock(int $orderId, int $userId): bool
    {
        $ttl = config('orderlocking.lock_ttl', 60);
        $expiresAt = Carbon::now()->addSeconds($ttl);

        $affected = DB::table('order_locks')
            ->where('order_id', $orderId)
            ->where('user_id', $userId)
            ->update([
                'expires_at' => $expiresAt,
                'updated_at' => Carbon::now(),
            ]);

        return $affected > 0;
    }

    /**
     * Explicitly unlock an order
     *
     * @param int $orderId
     * @param int $userId
     * @return bool
     */
    public function unlockOrder(int $orderId, int $userId): bool
    {
        $deleted = DB::table('order_locks')
            ->where('order_id', $orderId)
            ->where('user_id', $userId)
            ->delete();

        return $deleted > 0;
    }

    /**
     * Clean up expired locks (optional, can be run via cron)
     *
     * @return int Number of locks deleted
     */
    public function cleanupExpiredLocks(): int
    {
        return DB::table('order_locks')
            ->where('expires_at', '<', Carbon::now())
            ->delete();
    }

    /**
     * Force unlock an order (admin only)
     *
     * @param int $orderId
     * @return bool
     */
    public function forceUnlock(int $orderId): bool
    {
        $deleted = DB::table('order_locks')
            ->where('order_id', $orderId)
            ->delete();

        return $deleted > 0;
    }
}
