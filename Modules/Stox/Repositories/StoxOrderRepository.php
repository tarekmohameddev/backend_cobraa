<?php

declare(strict_types=1);

namespace Modules\Stox\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Stox\Entities\StoxOrder;

class StoxOrderRepository
{
    public function paginate(array $filter = []): LengthAwarePaginator
    {
        $query = StoxOrder::query()->with([
            'account',
            'order.user',
        ]);

        if ($status = data_get($filter, 'status')) {
            $query->where('export_status', $status);
        }

        if ($accountId = data_get($filter, 'stox_account_id')) {
            $query->where('stox_account_id', $accountId);
        }

        if ($orderId = data_get($filter, 'order_id')) {
            $query->where('order_id', $orderId);
        }

        if ($from = data_get($filter, 'from_date')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = data_get($filter, 'to_date')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $perPage = (int) data_get($filter, 'per_page', 15);

        return $query
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}

