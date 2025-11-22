<?php

declare(strict_types=1);

namespace Modules\Stox\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Stox\Entities\StoxAccount;

class StoxAccountRepository
{
    public function paginate(array $filter = []): LengthAwarePaginator
    {
        $query = StoxAccount::query()->withCount('orders');

        if ($status = data_get($filter, 'status')) {
            $query->where('status', $status);
        }

        if ($search = data_get($filter, 'search')) {
            $query->where(static function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $perPage = (int) data_get($filter, 'per_page', 15);

        return $query
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}

