<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Modules\EasyOrders\Entities\EasyOrdersTempOrder;

class EasyOrdersTempOrderRepository
{
	public function paginate(array $filter = []): LengthAwarePaginator
	{
		$query = $this->buildQuery($filter)->with('store');

		$perPage = (int) data_get($filter, 'perPage', 15);
		return $query->orderByDesc('id')->paginate($perPage);
	}

	/**
	 * Stats for temp orders under the same scope as listing filters.
	 *
	 * By default, this keeps store/date/search scoping, but ignores status/issue
	 * filters so the UI can show a full distribution within that scope.
	 */
	public function stats(array $filter = []): array
	{
		// Remove status/issue filters to show distribution across all statuses.
		$scope = Arr::except($filter, ['status', 'has_errors', 'has_error', 'issue', 'only_errors']);

		$query = $this->buildQuery($scope);

		$row = $query->selectRaw('COUNT(*) as total')
			->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending")
			->selectRaw("SUM(CASE WHEN status = 'waiting_payment' THEN 1 ELSE 0 END) as waiting_payment")
			->selectRaw("SUM(CASE WHEN status = 'validated' THEN 1 ELSE 0 END) as validated")
			->selectRaw("SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved")
			->selectRaw("SUM(CASE WHEN status = 'imported' THEN 1 ELSE 0 END) as imported")
			->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
			->selectRaw("SUM(CASE WHEN status = 'import_failed' THEN 1 ELSE 0 END) as import_failed")
			->selectRaw("SUM(CASE WHEN status IN ('failed','import_failed') THEN 1 ELSE 0 END) as errors")
			->selectRaw("SUM(CASE WHEN failure_reason IS NOT NULL AND failure_reason <> '' THEN 1 ELSE 0 END) as with_failure_reason")
			->first();

		return [
			'total' => (int) data_get($row, 'total', 0),
			'pending' => (int) data_get($row, 'pending', 0),
			'waiting_payment' => (int) data_get($row, 'waiting_payment', 0),
			'validated' => (int) data_get($row, 'validated', 0),
			'approved' => (int) data_get($row, 'approved', 0),
			'imported' => (int) data_get($row, 'imported', 0),
			'failed' => (int) data_get($row, 'failed', 0),
			'import_failed' => (int) data_get($row, 'import_failed', 0),
			'errors' => (int) data_get($row, 'errors', 0),
			'with_failure_reason' => (int) data_get($row, 'with_failure_reason', 0),
		];
	}

	public function findDuplicate(int $storeId, string $externalOrderId): ?EasyOrdersTempOrder
	{
		return EasyOrdersTempOrder::query()
			->where('store_id', $storeId)
			->where('external_order_id', $externalOrderId)
			->first();
	}

	private function buildQuery(array $filter = []): Builder
	{
		$query = EasyOrdersTempOrder::query();

		if ($status = data_get($filter, 'status')) {
			$query->where('status', $status);
		}

		if ($storeId = data_get($filter, 'store_id')) {
			$query->where('store_id', $storeId);
		}

		if ($dateFrom = data_get($filter, 'date_from')) {
			$query->whereDate('created_at', '>=', $dateFrom);
		}

		if ($dateTo = data_get($filter, 'date_to')) {
			$query->whereDate('created_at', '<=', $dateTo);
		}

		if ($search = data_get($filter, 'search')) {
			$query->where(function (Builder $q) use ($search) {
				$q->where('customer_name', 'like', "%{$search}%")
					->orWhere('customer_phone', 'like', "%{$search}%")
					->orWhere('external_order_id', 'like', "%{$search}%")
					->orWhere('short_id', 'like', "%{$search}%");
			});
		}

		// Filter to only orders that have issues/errors.
		$hasErrors = data_get($filter, 'has_errors', data_get($filter, 'has_error', data_get($filter, 'only_errors')));
		if (!is_null($hasErrors) && filter_var($hasErrors, FILTER_VALIDATE_BOOL)) {
			$query->where(function (Builder $q) {
				$q->whereIn('status', ['failed', 'import_failed'])
					->orWhereNotNull('failure_reason');
			});
		}

		// Advanced issue filter: issue=validation|import|waiting_payment|any
		$issue = data_get($filter, 'issue');
		if ($issue !== null && $issue !== '') {
			$issue = strtolower((string) $issue);
			if (in_array($issue, ['1', 'true', 'yes', 'any'], true)) {
				$query->where(function (Builder $q) {
					$q->whereIn('status', ['failed', 'import_failed'])
						->orWhereNotNull('failure_reason');
				});
			} elseif ($issue === 'validation') {
				$query->where('status', 'failed');
			} elseif ($issue === 'import') {
				$query->where('status', 'import_failed');
			} elseif (in_array($issue, ['waiting_payment', 'waiting'], true)) {
				$query->where('status', 'waiting_payment');
			}
		}

		return $query;
	}
}


