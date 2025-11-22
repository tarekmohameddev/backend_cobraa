<?php

declare(strict_types=1);

namespace Modules\Stox\Jobs;

use App\Models\Order;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Stox\Entities\StoxAccount;
use Modules\Stox\Services\StoxOrderExportService;

class ExportOrderToStoxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $overrideData
     */
    public function __construct(
        private readonly int $orderId,
        private readonly int $stoxAccountId,
        private readonly array $overrideData = [],
        private readonly ?int $triggeredByUserId = null,
        private readonly string $triggerType = 'manual'
    ) {
        $this->onQueue(config('stox.queue_name', 'default'));
    }

    public function handle(StoxOrderExportService $exportService): void
    {
        $order = Order::query()->findOrFail($this->orderId);
        $account = StoxAccount::query()->findOrFail($this->stoxAccountId);
        $user = $this->triggeredByUserId ? User::query()->find($this->triggeredByUserId) : null;

        $exportService->export($order, $account, $this->overrideData, $user, $this->triggerType);
    }
}

