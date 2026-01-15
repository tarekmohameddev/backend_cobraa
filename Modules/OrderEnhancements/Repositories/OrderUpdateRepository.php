<?php

declare(strict_types=1);

namespace Modules\OrderEnhancements\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\OrderEnhancements\Entities\OrderUpdate;

class OrderUpdateRepository
{
    public function paginateForOrder(int $orderId, array $filter = []): LengthAwarePaginator
    {
        $query = OrderUpdate::query()
            ->with(['user:id,firstname,lastname,email'])
            ->where('order_id', $orderId);

        $this->applyFilters($query, $filter);

        $perPage = (int) data_get($filter, 'per_page', 20);

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    public function create(array $data): OrderUpdate
    {
        return OrderUpdate::query()->create($data);
    }

    private function applyFilters($query, array $filter): void
    {
        if ($updateType = data_get($filter, 'update_type')) {
            $query->where('update_type', $updateType);
        }

        if ($from = data_get($filter, 'from_date')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = data_get($filter, 'to_date')) {
            $query->whereDate('created_at', '<=', $to);
        }
    }
}
