<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\EasyOrders\Entities\EasyOrdersWebhookLog;
use Modules\EasyOrders\Services\WebhookService;

class ProcessWebhookJob implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	public int $tries = 5;

	/** @var array<int,int> */
	public array $backoff = [10, 30, 60, 120, 300];

	public function __construct(public int $webhookLogId)
	{
		$this->onQueue('default');
	}

	public function handle(WebhookService $service): void
	{
		$service->processWebhookLog($this->webhookLogId);
	}

	public function failed(\Throwable $e): void
	{
		try {
			/** @var EasyOrdersWebhookLog|null $log */
			$log = EasyOrdersWebhookLog::query()->find($this->webhookLogId);
			if (!$log) {
				return;
			}

			$log->processing_status = 'failed';
			$log->http_status = $log->http_status ?? 500;
			$log->error = trim(($log->error ? $log->error.'; ' : '').'Processing failed after retries: '.$e->getMessage());
			$log->save();
		} catch (\Throwable) {
			// Never throw from failed() hook.
		}
	}
}

