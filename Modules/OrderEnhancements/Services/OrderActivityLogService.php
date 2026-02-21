<?php
declare(strict_types=1);

namespace Modules\OrderEnhancements\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Modules\OrderEnhancements\Entities\OrderActivityLog;
use Throwable;

class OrderActivityLogService
{
    public const TYPE_CREATED = 'created';
    public const TYPE_STATUS_CHANGED = 'status_changed';
    public const TYPE_ITEMS_MODIFIED = 'items_modified';
    public const TYPE_DELIVERYMAN_ASSIGNED = 'deliveryman_assigned';
    public const TYPE_TRACKING_UPDATED = 'tracking_updated';

    public function logCreation(Order $order, ?User $user): void
    {
        $this->safeCreate([
            'order_id' => $order->id,
            'user_id' => $user?->id,
            'activity_type' => self::TYPE_CREATED,
            'description' => 'Order created',
            'metadata' => [
                'source' => $this->requestSource(),
            ],
        ]);
    }

    public function logStatusChange(Order $order, ?User $user, string $from, string $to, array $metadata = []): void
    {
        $this->safeCreate([
            'order_id' => $order->id,
            'user_id' => $user?->id,
            'activity_type' => self::TYPE_STATUS_CHANGED,
            'description' => "Status changed: {$from} -> {$to}",
            'metadata' => array_merge([
                'from' => $from,
                'to' => $to,
                'source' => $this->requestSource(),
            ], $metadata),
        ]);
    }

    public function logItemsModified(
        Order $order,
        ?User $user,
        int $linesAdded,
        int $unitsAdded,
        int $linesRemoved,
        int $unitsRemoved,
        array $details = [],
        array $metadata = [],
    ): void {
        $this->safeCreate([
            'order_id' => $order->id,
            'user_id' => $user?->id,
            'activity_type' => self::TYPE_ITEMS_MODIFIED,
            'description' => 'Order items modified',
            'lines_added' => max($linesAdded, 0),
            'units_added' => max($unitsAdded, 0),
            'lines_removed' => max($linesRemoved, 0),
            'units_removed' => max($unitsRemoved, 0),
            'metadata' => array_merge([
                'source' => $this->requestSource(),
                'details' => $details,
            ], $metadata),
        ]);
    }

    public function logDeliverymanAssigned(Order $order, ?User $user, User $deliveryman, array $metadata = []): void
    {
        $this->safeCreate([
            'order_id' => $order->id,
            'user_id' => $user?->id,
            'activity_type' => self::TYPE_DELIVERYMAN_ASSIGNED,
            'description' => 'Deliveryman assigned',
            'metadata' => array_merge([
                'source' => $this->requestSource(),
                'deliveryman_id' => $deliveryman->id,
                'deliveryman_name' => trim(($deliveryman->firstname ?? '') . ' ' . ($deliveryman->lastname ?? '')),
            ], $metadata),
        ]);
    }

    public function logTrackingUpdate(Order $order, ?User $user, array $trackingData, array $metadata = []): void
    {
        $this->safeCreate([
            'order_id' => $order->id,
            'user_id' => $user?->id,
            'activity_type' => self::TYPE_TRACKING_UPDATED,
            'description' => 'Tracking updated',
            'metadata' => array_merge([
                'source' => $this->requestSource(),
                'tracking' => $trackingData,
            ], $metadata),
        ]);
    }

    private function safeCreate(array $data): ?OrderActivityLog
    {
        try {
            return OrderActivityLog::query()->create($data);
        } catch (Throwable $e) {
            Log::error('OrderActivityLog failed', [
                'exception' => $e->getMessage(),
                'order_id' => data_get($data, 'order_id'),
                'user_id' => data_get($data, 'user_id'),
                'activity_type' => data_get($data, 'activity_type'),
            ]);
            return null;
        }
    }

    private function requestSource(): array
    {
        try {
            $request = request();

            return [
                'path' => method_exists($request, 'path') ? $request->path() : null,
                'method' => method_exists($request, 'method') ? $request->method() : null,
                'ip' => method_exists($request, 'ip') ? $request->ip() : null,
                'user_agent' => method_exists($request, 'userAgent') ? $request->userAgent() : null,
            ];
        } catch (Throwable) {
            return [];
        }
    }
}

