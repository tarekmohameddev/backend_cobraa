<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\EasyOrders\Services\ImportService;

class ImportTempOrderJob implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	public function __construct(public int $tempOrderId)
	{
		$this->onQueue('default');
	}

	public function handle(ImportService $service): void
	{
		$service->import($this->tempOrderId);
	}
}


