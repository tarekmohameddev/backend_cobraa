<?php

declare(strict_types=1);

namespace Modules\Stox\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Stox\Entities\StoxOperationLog;

class StoxOperationLogRepository
{
    public function paginate(array $filter = []): LengthAwarePaginator
    {
        $query = StoxOperationLog::query()
            ->with(['account', 'stoxOrder', 'order', 'user']);

        $this->applyFilters($query, $filter);

        $perPage = (int) data_get($filter, 'per_page', 20);

        return $query->orderByDesc('id')->paginate($perPage);
    }

    public function all(array $filter = []): Collection
    {
        $query = StoxOperationLog::query()
            ->with(['account', 'stoxOrder', 'order', 'user']);

        $this->applyFilters($query, $filter);

        return $query->orderByDesc('id')->get();
    }

    private function applyFilters($query, array $filter): void
    {
        if ($operationType = data_get($filter, 'operation_type')) {
            $query->where('operation_type', $operationType);
        }

        if ($accountId = data_get($filter, 'stox_account_id')) {
            $query->where('stox_account_id', $accountId);
        }

        if ($orderId = data_get($filter, 'order_id')) {
            $query->where('order_id', $orderId);
        }

        if ($triggerType = data_get($filter, 'trigger_type')) {
            $query->where('trigger_type', $triggerType);
        }

        if ($from = data_get($filter, 'from_date')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = data_get($filter, 'to_date')) {
            $query->whereDate('created_at', '<=', $to);
        }
    }
}

