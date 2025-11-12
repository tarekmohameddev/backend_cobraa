<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\EasyOrders\Entities\EasyOrdersStore;

class EasyOrdersStoreRepository
{
	public function paginate(array $filter = []): LengthAwarePaginator
	{
		$query = EasyOrdersStore::query();

		if ($status = data_get($filter, 'status')) {
			$query->where('status', $status);
		}

		if ($search = data_get($filter, 'search')) {
			$query->where(function ($q) use ($search) {
				$q->where('name', 'like', "%{$search}%")
					->orWhere('external_store_id', 'like', "%{$search}%");
			});
		}

		$perPage = (int) data_get($filter, 'perPage', 15);
		return $query->orderByDesc('id')->paginate($perPage);
	}

	public function findByWebhookSecret(string $secret): ?EasyOrdersStore
	{
		return EasyOrdersStore::query()->where('webhook_secret', $secret)->first();
	}
}


