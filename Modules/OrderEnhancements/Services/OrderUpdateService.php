<?php

declare(strict_types=1);

namespace Modules\OrderEnhancements\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\OrderEnhancements\Entities\OrderUpdate;
use Modules\OrderEnhancements\Repositories\OrderUpdateRepository;

class OrderUpdateService
{
    public function __construct(
        private readonly OrderUpdateRepository $repository
    ) {}

    public function getForOrder(Order $order, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginateForOrder($order->id, $filters);
    }

    public function create(Order $order, User $user, array $data): OrderUpdate
    {
        return $this->repository->create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'update_type' => $data['update_type'] ?? 'note',
            'content' => $data['content'],
            'metadata' => $data['metadata'] ?? null,
            'is_internal' => $data['is_internal'] ?? true,
        ]);
    }
}
