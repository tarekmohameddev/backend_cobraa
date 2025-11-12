<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\EasyOrders\Entities\EasyOrdersTempOrder;

class EasyOrdersTempOrderRepository
{
	public function paginate(array $filter = []): LengthAwarePaginator
	{
		$query = EasyOrdersTempOrder::query()->with('store');

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
			$query->where(function ($q) use ($search) {
				$q->where('customer_name', 'like', "%{$search}%")
					->orWhere('customer_phone', 'like', "%{$search}%")
					->orWhere('external_order_id', 'like', "%{$search}%")
					->orWhere('short_id', 'like', "%{$search}%");
			});
		}

		$perPage = (int) data_get($filter, 'perPage', 15);
		return $query->orderByDesc('id')->paginate($perPage);
	}

	public function findDuplicate(int $storeId, string $externalOrderId): ?EasyOrdersTempOrder
	{
		return EasyOrdersTempOrder::query()
			->where('store_id', $storeId)
			->where('external_order_id', $externalOrderId)
			->first();
	}
}


