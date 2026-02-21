<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Console\Commands;

use Illuminate\Console\Command;
use Modules\EasyOrders\Entities\EasyOrdersWebhookLog;
use Modules\EasyOrders\Jobs\ProcessWebhookJob;

class ProcessStuckWebhooksCommand extends Command
{
	protected $signature = 'easyorders:process-stuck-webhooks {--limit=200 : Max logs to process per run}';

	protected $description = 'Re-dispatch processing jobs for stuck EasyOrders webhook logs';

	public function handle(): int
	{
		$limit = max(1, (int) $this->option('limit'));

		$receivedCutoff = now()->subMinutes(2);

		$received = EasyOrdersWebhookLog::query()
			->where('processing_status', 'received')
			->where('created_at', '<', $receivedCutoff)
			->orderBy('id')
			->limit($limit)
			->get(['id']);

		$requeued = 0;
		foreach ($received as $row) {
			ProcessWebhookJob::dispatch((int) $row->id)->onQueue('default');
			$requeued++;
		}

		$failedCutoff = now()->subMinutes(5);

		$failed = EasyOrdersWebhookLog::query()
			->where('processing_status', 'failed')
			->where('attempts', '<', 10)
			->where('updated_at', '<', $failedCutoff)
			->orderBy('id')
			->limit(max(0, $limit - $requeued))
			->get(['id']);

		foreach ($failed as $row) {
			ProcessWebhookJob::dispatch((int) $row->id)->onQueue('default');
			$requeued++;
		}

		$this->info("Re-dispatched {$requeued} EasyOrders webhook processing job(s).");

		return self::SUCCESS;
	}
}

