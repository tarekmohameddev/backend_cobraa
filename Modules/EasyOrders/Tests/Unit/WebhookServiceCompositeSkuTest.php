<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Modules\EasyOrders\Services\WebhookService;
use Tests\TestCase;

class WebhookServiceCompositeSkuTest extends TestCase
{
	public function test_normalize_order_payload_splits_composite_sku_into_multiple_items(): void
	{
		// We don't care about external API call here, so disable module to avoid accidental HTTP.
		Config::set('easyorders.enabled', false);

		$service = app(WebhookService::class);

		$payload = [
			'id' => 'order-1',
			'store_id' => 'store-1',
			'status' => 'pending',
			'short_id' => 123,
			'cart_items' => [
				[
					'id' => 'line-1',
					'price' => 300,
					'quantity' => 2,
					'product' => [
						'id' => 'p1',
						'name' => 'Composite Product',
						'sku' => 'Forev-Q1S+Forev-w502+OP1-headphone',
						'slug' => 'composite-product',
						'thumb' => null,
						'images' => [],
					],
					'variant' => [
						'id' => null,
						'taager_code' => null,
						'variation_props' => [],
					],
				],
			],
		];

		$result = $service->normalizeOrderPayload($payload, 'order-1');

		$normalized = $result['normalized'] ?? [];
		$items = $normalized['items'] ?? [];

		$this->assertCount(3, $items);

		$skus = array_map(static fn ($item) => $item['product']['sku'] ?? null, $items);
		$this->assertSame(
			['Forev-Q1S', 'Forev-w502', 'OP1-headphone'],
			$skus
		);

		// Each split item should keep the original quantity
		foreach ($items as $item) {
			$this->assertSame(2, $item['quantity']);

			// For composite SKUs we ignore external per-item price
			$this->assertNull($item['price']);
			$this->assertArrayHasKey('resolved', $item);
			$this->assertArrayHasKey('price_policy', $item['resolved']);
			$this->assertNull($item['resolved']['price_policy']['external_price']);
		}
	}
}


