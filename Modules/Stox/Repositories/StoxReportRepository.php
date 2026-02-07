<?php

declare(strict_types=1);

namespace Modules\Stox\Repositories;

use Illuminate\Support\Collection;
use Modules\Stox\Entities\StoxOperationLog;

class StoxReportRepository
{
    /**
     * Get counts of exported orders per employee (by operation log user), filtered by exported_at.
     *
     * @param  array{date_from?: string, date_to?: string}  $filter
     * @return Collection<int, object{user_id: int|null, user_name: string|null, orders_exported_count: int}>
     */
    public function getEmployeeExportCounts(array $filter): Collection
    {
        $query = StoxOperationLog::query()
            ->selectRaw('stox_operation_logs.user_id as user_id')
            ->selectRaw('TRIM(CONCAT(COALESCE(users.firstname, ""), " ", COALESCE(users.lastname, ""))) as user_name')
            ->selectRaw('COUNT(DISTINCT stox_orders.order_id) as orders_exported_count')
            ->join('stox_orders', 'stox_orders.id', '=', 'stox_operation_logs.stox_order_id')
            ->leftJoin('users', 'users.id', '=', 'stox_operation_logs.user_id')
            ->whereNotNull('stox_orders.exported_at');

        $this->applyDateFilters($query, $filter);

        $query->groupBy('stox_operation_logs.user_id')
            ->groupBy('users.firstname')
            ->groupBy('users.lastname');

        $results = $query->get();

        return $results->map(static function ($row) {
            return (object) [
                'user_id' => $row->user_id,
                'user_name' => $row->user_name !== null ? trim((string) $row->user_name) : null,
                'orders_exported_count' => (int) $row->orders_exported_count,
            ];
        });
    }

    private function applyDateFilters($query, array $filter): void
    {
        $from = data_get($filter, 'date_from');
        $to = data_get($filter, 'date_to');

        if ($from !== null && $from !== '') {
            $query->whereDate('stox_orders.exported_at', '>=', $from);
        }

        if ($to !== null && $to !== '') {
            $query->whereDate('stox_orders.exported_at', '<=', $to);
        }
    }
}
