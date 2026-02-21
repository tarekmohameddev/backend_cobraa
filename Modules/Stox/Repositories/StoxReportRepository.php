<?php

declare(strict_types=1);

namespace Modules\Stox\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Stox\Entities\StoxOperationLog;
use Throwable;

class StoxReportRepository
{
    /**
     * Get counts of exported orders per employee (by operation log user), filtered by exported_at.
     *
     * @param  array{date_from?: string, date_to?: string}  $filter
     * @return Collection<int, object{user_id: int|null, user_name: string|null, orders_exported_count: int, total_lines_added: int, total_units_added: int}>
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

        $this->applyDateFilters($query, $filter, 'stox_orders.exported_at');

        $query->groupBy('stox_operation_logs.user_id')
            ->groupBy('users.firstname')
            ->groupBy('users.lastname');

        $exportResults = $query->get();

        $exportRows = $exportResults->map(static function ($row) {
            return (object) [
                'user_id' => $row->user_id,
                'user_name' => $row->user_name !== null ? trim((string) $row->user_name) : null,
                'orders_exported_count' => (int) $row->orders_exported_count,
                'total_lines_added' => 0,
                'total_units_added' => 0,
            ];
        });

        $activityRows = collect();

        try {
            $activityQuery = DB::table('order_activity_logs')
                ->selectRaw('order_activity_logs.user_id as user_id')
                ->selectRaw('TRIM(CONCAT(COALESCE(users.firstname, ""), " ", COALESCE(users.lastname, ""))) as user_name')
                ->selectRaw('COALESCE(SUM(order_activity_logs.lines_added), 0) as total_lines_added')
                ->selectRaw('COALESCE(SUM(order_activity_logs.units_added), 0) as total_units_added')
                ->leftJoin('users', 'users.id', '=', 'order_activity_logs.user_id')
                ->where('order_activity_logs.activity_type', '=', 'items_modified');

            $this->applyDateFilters($activityQuery, $filter, 'order_activity_logs.created_at');

            $activityQuery->groupBy('order_activity_logs.user_id')
                ->groupBy('users.firstname')
                ->groupBy('users.lastname');

            $activityResults = $activityQuery->get();

            $activityRows = collect($activityResults)->map(static function ($row) {
                return (object) [
                    'user_id' => $row->user_id,
                    'user_name' => $row->user_name !== null ? trim((string) $row->user_name) : null,
                    'total_lines_added' => (int) $row->total_lines_added,
                    'total_units_added' => (int) $row->total_units_added,
                ];
            });
        } catch (Throwable $e) {
            Log::error('Employee export report: activity log query failed', [
                'exception' => $e->getMessage(),
            ]);
        }

        $byUser = [];

        foreach ($exportRows as $row) {
            $key = $row->user_id === null ? 'null' : (string) $row->user_id;
            $byUser[$key] = $row;
        }

        foreach ($activityRows as $row) {
            $key = $row->user_id === null ? 'null' : (string) $row->user_id;

            if (!isset($byUser[$key])) {
                $byUser[$key] = (object) [
                    'user_id' => $row->user_id,
                    'user_name' => $row->user_name,
                    'orders_exported_count' => 0,
                    'total_lines_added' => (int) $row->total_lines_added,
                    'total_units_added' => (int) $row->total_units_added,
                ];
                continue;
            }

            $byUser[$key]->total_lines_added = (int) $row->total_lines_added;
            $byUser[$key]->total_units_added = (int) $row->total_units_added;

            if (empty($byUser[$key]->user_name) && !empty($row->user_name)) {
                $byUser[$key]->user_name = $row->user_name;
            }
        }

        return collect(array_values($byUser));
    }

    private function applyDateFilters($query, array $filter, string $column): void
    {
        $from = data_get($filter, 'date_from');
        $to = data_get($filter, 'date_to');

        if ($from !== null && $from !== '') {
            $query->whereDate($column, '>=', $from);
        }

        if ($to !== null && $to !== '') {
            $query->whereDate($column, '<=', $to);
        }
    }
}
