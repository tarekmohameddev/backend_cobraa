<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Console\Commands;

use Illuminate\Console\Command;
use Modules\EasyOrders\Services\ProductSyncService;

class SyncEasyOrdersProducts extends Command
{
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'easyorders:products:sync {--page=1 : Starting page}';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Sync products from EasyOrders into the local catalog';

	public function handle(ProductSyncService $service): int
	{
		if (!config('easyorders.enabled')) {
			$this->warn('EasyOrders integration is disabled (easyorders.enabled = false).');
			return self::SUCCESS;
		}

		$page = (int) $this->option('page');
		$service->syncAll($page);

		$this->info('EasyOrders products sync triggered starting from page ' . $page);

		return self::SUCCESS;
	}
}


