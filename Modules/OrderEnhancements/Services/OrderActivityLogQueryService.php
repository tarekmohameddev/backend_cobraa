<?php
declare(strict_types=1);

namespace Modules\OrderEnhancements\Services;

use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\OrderEnhancements\Repositories\OrderActivityLogRepository;

class OrderActivityLogQueryService
{
    public function __construct(
        private readonly OrderActivityLogRepository $repository
    ) {}

    public function getForOrder(Order $order, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginateForOrder($order->id, $filters);
    }
}

