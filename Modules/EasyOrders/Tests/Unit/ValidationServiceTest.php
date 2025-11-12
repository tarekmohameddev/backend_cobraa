<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EasyOrders\Entities\EasyOrdersStore;
use Modules\EasyOrders\Entities\EasyOrdersTempOrder;
use Modules\EasyOrders\Services\ValidationService;
use Tests\TestCase;

class ValidationServiceTest extends TestCase
{
	use RefreshDatabase;

	public function test_validation_marks_failed_on_unknown_sku(): void
	{
		$store = EasyOrdersStore::query()->create([
			'name' => 'Test',
			'webhook_secret' => 's',
			'status' => 'active',
		]);

		$temp = EasyOrdersTempOrder::query()->create([
			'store_id' => $store->id,
			'external_order_id' => 'o1',
			'status' => 'pending',
			'normalized' => [
				'items' => [
					[
						'price' => 10,
						'quantity' => 1,
						'product' => ['sku' => 'unknown'],
						'variant' => ['variant_sku' => null],
					],
				],
				'totals' => ['cost' => 10, 'shipping_cost' => 0, 'total_cost' => 10],
			],
		]);

		$service = app(ValidationService::class);
		$service->validate($temp->id);

		$temp->refresh();
		$this->assertSame('failed', $temp->status);
		$this->assertNotEmpty($temp->failure_reason);
	}
}


