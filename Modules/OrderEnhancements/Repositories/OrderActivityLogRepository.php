<?php
declare(strict_types=1);

namespace Modules\OrderEnhancements\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\OrderEnhancements\Entities\OrderActivityLog;

class OrderActivityLogRepository
{
    public function paginateForOrder(int $orderId, array $filter = []): LengthAwarePaginator
    {
        $query = OrderActivityLog::query()
            ->with(['user:id,firstname,lastname,email'])
            ->where('order_id', $orderId);

        $perPage = (int) data_get($filter, 'per_page', 20);

        return $query->orderByDesc('created_at')->paginate($perPage);
    }
}

