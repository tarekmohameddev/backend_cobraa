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
use Modules\EasyOrders\Services\ProductSyncService;

class SyncProductsJob implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	/**
	 * The number of times the job may be attempted.
	 */
	public int $tries = 1; // Set to 1 to prevent retries on timeout

	/**
	 * The number of seconds to wait before retrying the job.
	 */
	public int $backoff = 60;

	/**
	 * The number of seconds the job can run before timing out.
	 * Set to a high value for long-running syncs (8 hours = 28800 seconds).
	 */
	public int $timeout = 28800; // 8 hours - allow long-running syncs

	/**
	 * Get the number of seconds to wait before retrying the job.
	 * This prevents the job from being considered "stuck" during long operations.
	 */
	public function retryAfter(): int
	{
		return 3600; // 1 hour - allow job to run for up to 1 hour before considering it stuck
	}

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
		// Increase execution time and memory limit for long-running sync
		ini_set('max_execution_time', '0');
		ini_set('memory_limit', '512M');

		$syncRecord = $this->syncId 
			? EasyOrdersProductSync::find($this->syncId)
			: null;

		Log::info('EasyOrders product sync started', [
			'page' => $this->page,
			'user_id' => $this->userId,
			'sync_id' => $this->syncId,
		]);

		try {
			// Pass a callback to touch the job periodically to prevent timeout
			$touchCallback = function () {
				$this->job?->touch();
			};

			$service->syncAll($this->page, $syncRecord, $touchCallback);

			Log::info('EasyOrders product sync completed', [
				'page' => $this->page,
				'user_id' => $this->userId,
				'sync_id' => $this->syncId,
			]);
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
