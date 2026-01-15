<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\EasyOrders\Entities\EasyOrdersProductSync;
use Modules\EasyOrders\Services\ProductSyncService;

class SyncOneProductJob implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	public int $tries = 1;

	public function __construct(
		public string $externalProductId,
		public int $syncId,
	) {
		$this->onQueue('default');
	}

	public function handle(ProductSyncService $service): void
	{
		/** @var EasyOrdersProductSync|null $sync */
		$sync = EasyOrdersProductSync::query()->find($this->syncId);
		if (!$sync) {
			return;
		}

		try {
			$service->syncOne($this->externalProductId);

			DB::transaction(function () use ($sync) {
				$sync->refresh();
				$sync->products_synced = (int) $sync->products_synced + 1;
				$sync->save();
			});
		} catch (\Throwable $e) {
			Log::warning('EasyOrders product sync failed for product', [
				'sync_id' => $this->syncId,
				'external_id' => $this->externalProductId,
				'error' => $e->getMessage(),
			]);

			DB::transaction(function () use ($sync, $e) {
				$sync->refresh();
				$sync->products_failed = (int) $sync->products_failed + 1;
				$sync->error_message = $sync->error_message ?: $e->getMessage();
				$sync->save();
			});
		}

		// If dispatching is finished and we've processed everything, mark completed.
		$sync->refresh();
		$queued = (int) data_get($sync->metadata, 'queued_products', 0);
		$dispatchDone = (bool) data_get($sync->metadata, 'dispatch_done', false);
		$processed = (int) $sync->products_synced + (int) $sync->products_failed;

		if ($dispatchDone && $queued > 0 && $processed >= $queued && $sync->status !== EasyOrdersProductSync::STATUS_COMPLETED) {
			$sync->markAsCompleted();
		}
	}
}

