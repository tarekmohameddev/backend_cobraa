<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\EasyOrders\Entities\EasyOrdersProductSync;
use Modules\EasyOrders\Jobs\SyncOneProductJob;
use Modules\EasyOrders\Services\ProductSyncService;

class SyncProductsJob implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	public int $tries = 1;
	public int $timeout = 60; // keep this job short

	/**
	 * Create a new job instance.
	 */
	public function __construct(
		public int $page = 1,
		public ?int $userId = null,
		public ?int $syncId = null
	) {
		$this->onQueue('default');
	}

	/**
	 * Execute the job.
	 */
	public function handle(ProductSyncService $service): void
	{
		$syncRecord = $this->syncId 
			? EasyOrdersProductSync::find($this->syncId)
			: null;

		if (!$syncRecord) {
			return;
		}

		// Mark started once
		if ($syncRecord->status === EasyOrdersProductSync::STATUS_PENDING) {
			$syncRecord->markAsStarted();
		}

		try {
			$result = $service->listExternalProductIds($this->page);
			$ids = $result['ids'] ?? [];
			$totalPages = $result['total_pages'] ?? null;

			// Update progress and metadata
			$metadata = $syncRecord->metadata ?? [];
			$metadata['current_page'] = $this->page;
			if ($totalPages) {
				$metadata['total_pages'] = (int) $totalPages;
				$syncRecord->total_pages = (int) $totalPages;
			}

			// If no ids returned, we're done dispatching.
			if (empty($ids)) {
				$metadata['dispatch_done'] = true;
				$syncRecord->metadata = $metadata;
				$syncRecord->current_page = $this->page;
				$syncRecord->save();

				$queued = (int) data_get($metadata, 'queued_products', 0);
				$processed = (int) $syncRecord->products_synced + (int) $syncRecord->products_failed;
				if ($queued > 0 && $processed >= $queued) {
					$syncRecord->markAsCompleted();
				}

				return;
			}

			// Dispatch per-product jobs
			foreach ($ids as $externalId) {
				SyncOneProductJob::dispatch((string) $externalId, (int) $syncRecord->id)->onQueue('default');
			}

			$metadata['queued_products'] = (int) data_get($metadata, 'queued_products', 0) + count($ids);
			$syncRecord->metadata = $metadata;
			$syncRecord->current_page = $this->page;
			$syncRecord->save();

			// Dispatch next page dispatcher
			static::dispatch($this->page + 1, $this->userId, $this->syncId)->onQueue('default');
		} catch (\Throwable $e) {
			$errorMessage = $e->getMessage();
			
			if ($syncRecord) {
				$syncRecord->markAsFailed($errorMessage);
			}

			Log::error('EasyOrders product sync failed', [
				'page' => $this->page,
				'user_id' => $this->userId,
				'sync_id' => $this->syncId,
				'error' => $errorMessage,
				'trace' => $e->getTraceAsString(),
			]);

			throw $e; // Re-throw to trigger retry mechanism
		}
	}

	/**
	 * Handle a job failure.
	 */
	public function failed(\Throwable $exception): void
	{
		$syncRecord = $this->syncId 
			? EasyOrdersProductSync::find($this->syncId)
			: null;

		if ($syncRecord) {
			$syncRecord->markAsFailed($exception->getMessage());
		}

		Log::error('EasyOrders product sync job failed permanently', [
			'page' => $this->page,
			'user_id' => $this->userId,
			'sync_id' => $this->syncId,
			'error' => $exception->getMessage(),
			'trace' => $exception->getTraceAsString(),
		]);
	}
}
