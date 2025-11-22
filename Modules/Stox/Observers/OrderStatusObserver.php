<?php

declare(strict_types=1);

namespace Modules\Stox\Observers;

use App\Models\Order;
use Modules\Stox\Entities\StoxAccount;
use Modules\Stox\Jobs\ExportOrderToStoxJob;

class OrderStatusObserver
{
    public function updated(Order $order): void
    {
        if (!$order->wasChanged('status')) {
            return;
        }

        $accounts = StoxAccount::query()
            ->where('status', 'active')
            ->whereNotNull('auto_export_statuses')
            ->get();

        foreach ($accounts as $account) {
            if (!$this->shouldExportForAccount($order, $account)) {
                continue;
            }

            $delay = (int) $account->export_delay_minutes;
            $job = new ExportOrderToStoxJob(
                $order->id,
                $account->id,
                [],
                null,
                'automatic'
            );

            $delay > 0
                ? dispatch($job)->delay(now()->addMinutes($delay))
                : dispatch($job);
        }
    }

    private function shouldExportForAccount(Order $order, StoxAccount $account): bool
    {
        $statuses = collect($account->auto_export_statuses ?? [])->map(fn($status) => strtolower($status));
        if ($statuses->isEmpty() || !$statuses->contains(strtolower($order->status))) {
            return false;
        }

        $settings = collect($account->settings ?? []);

        $shopIds = collect($settings->get('shop_ids', []))->filter()->unique();
        if ($shopIds->isNotEmpty() && !$shopIds->contains((int) $order->shop_id)) {
            return false;
        }

        $paymentTypes = collect($settings->get('payment_types', []))->map(fn($type) => strtolower($type));
        $orderPaymentType = strtolower((string) ($order->payment_status ?? 'cod'));
        if ($paymentTypes->isNotEmpty() && !$paymentTypes->contains($orderPaymentType)) {
            return false;
        }

        return true;
    }
}

