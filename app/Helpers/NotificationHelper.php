<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Models\Order;
use App\Models\ParcelOrder;
use App\Models\Settings;
use Throwable;

class NotificationHelper
{
    public function deliveryManOrder(Order $order, ?string $type = 'deliveryman'): array
    {
        $km = 0;

        try {
            $km = (new Utility)->getDistance($order->shop?->lat_long, $order->location);
        } catch (Throwable) {}

        $second = Settings::where('key', 'deliveryman_order_acceptance_time')->first();

        return [
            'second'    => (string)data_get($second, 'value', 30),
            'id'        => (string)$order->id,
            'status'    => (string)$order->status,
            'km'        => (string)$km,
            'type'      => (string)$type
        ];
    }

    public function deliveryManParcelOrder(ParcelOrder $parcelOrder, string $type = 'deliveryman'): array
    {
        $km = (new Utility)->getDistance(
            $parcelOrder->address_from,
            $parcelOrder->address_to,
        );

        $second = Settings::where('key', 'deliveryman_order_acceptance_time')->first();

        return [
            'second'    => data_get($second, 'value', 30),
            'order'     => [
                'id'        => $parcelOrder->id,
                'status'    => $parcelOrder->status,
                'km'        => $km,
                'type'      => $type
            ],
        ];
    }

}
