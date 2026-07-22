<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Modules\EasyOrders\Services\WebhookService;
use Tests\TestCase;

/**
 * Verifies that normalizeCartItems() correctly stores external price metadata
 * (external_line_total, combo_group_id, combo_external_total) for both regular
 * and composite-SKU items.
 */
class WebhookServiceExternalPriceMetaTest extends TestCase
{
	private function makeService(): WebhookService
	{
		Config::set('easyorders.enabled', false);

		return app(WebhookService::class);
	}

	// -----------------------------------------------------------------------
	// Regular (non-composite) items
	// -----------------------------------------------------------------------

	public function test_regular_item_stores_external_line_total(): void
	{
		$result = $this->makeService()->normalizeOrderPayload([
			'id'         => 'ord-1',
			'store_id'   => 's1',
			'status'     => 'pending',
			'short_id'   => 1,
			'cart_items' => [
				[
					'id'       => 'line-1',
					'price'    => 120.00,
					'quantity' => 2,
					'product'  => ['id' => 'p1', 'name' => 'Widget', 'sku' => 'SKU-A', 'slug' => 'widget', 'thumb' => null, 'images' => []],
					'variant'  => ['id' => null, 'taager_code' => null, 'variation_props' => []],
				],
			],
		], 'ord-1');

		$item = $result['normalized']['items'][0];

		// external_line_total = price × qty
		$this->assertSame(240.0, $item['resolved']['price_policy']['external_line_total']);
		// Regular items keep the per-unit external_price
		$this->assertSame(120.0, $item['resolved']['price_policy']['external_price']);
		// No combo metadata on regular items
		$this->assertNull(data_get($item, 'resolved.price_policy.combo_group_id'));
		$this->assertNull(data_get($item, 'resolved.price_policy.combo_external_total'));
	}

	public function test_regular_item_with_null_price_stores_null_line_total(): void
	{
		$result = $this->makeService()->normalizeOrderPayload([
			'id'         => 'ord-2',
			'store_id'   => 's1',
			'status'     => 'pending',
			'short_id'   => 2,
			'cart_items' => [
				[
					'id'       => 'line-1',
					'price'    => null,
					'quantity' => 1,
					'product'  => ['id' => 'p1', 'name' => 'Widget', 'sku' => 'SKU-B', 'slug' => 'widget', 'thumb' => null, 'images' => []],
					'variant'  => ['id' => null, 'taager_code' => null, 'variation_props' => []],
				],
			],
		], 'ord-2');

		$item = $result['normalized']['items'][0];

		$this->assertNull($item['resolved']['price_policy']['external_line_total']);
		$this->assertNull($item['resolved']['price_policy']['external_price']);
	}

	// -----------------------------------------------------------------------
	// Composite (combo) SKU items
	// -----------------------------------------------------------------------

	public function test_composite_sku_parts_share_combo_group_id_and_external_total(): void
	{
		$result = $this->makeService()->normalizeOrderPayload([
			'id'         => 'ord-3',
			'store_id'   => 's1',
			'status'     => 'pending',
			'short_id'   => 3,
			'cart_items' => [
				[
					'id'       => 'line-1',
					'price'    => 950.00,
					'quantity' => 1,
					'product'  => ['id' => 'p1', 'name' => 'Combo', 'sku' => 'GR-Q1+Forev-w502+OP1-headphone', 'slug' => 'combo', 'thumb' => null, 'images' => []],
					'variant'  => ['id' => null, 'taager_code' => null, 'variation_props' => []],
				],
			],
		], 'ord-3');

		$items = $result['normalized']['items'];

		$this->assertCount(3, $items, 'Composite SKU should produce 3 split items');

		// All parts must have the same non-null combo_group_id
		$groupIds = array_unique(array_map(
			static fn ($i) => data_get($i, 'resolved.price_policy.combo_group_id'),
			$items,
		));
		$this->assertCount(1, $groupIds, 'All split parts must share the same combo_group_id');
		$this->assertNotNull($groupIds[array_key_first($groupIds)]);

		// combo_external_total = original price × qty = 950 × 1
		foreach ($items as $item) {
			$this->assertSame(950.0, $item['resolved']['price_policy']['combo_external_total']);
			// Per-item external_price and external_line_total are cleared for split parts
			$this->assertNull($item['resolved']['price_policy']['external_price']);
			$this->assertNull($item['resolved']['price_policy']['external_line_total']);
		}
	}

	public function test_composite_sku_with_null_price_stores_null_combo_external_total(): void
	{
		$result = $this->makeService()->normalizeOrderPayload([
			'id'         => 'ord-4',
			'store_id'   => 's1',
			'status'     => 'pending',
			'short_id'   => 4,
			'cart_items' => [
				[
					'id'       => 'line-1',
					'price'    => null,
					'quantity' => 2,
					'product'  => ['id' => 'p1', 'name' => 'Combo', 'sku' => 'SKU-A+SKU-B', 'slug' => 'combo', 'thumb' => null, 'images' => []],
					'variant'  => ['id' => null, 'taager_code' => null, 'variation_props' => []],
				],
			],
		], 'ord-4');

		foreach ($result['normalized']['items'] as $item) {
			$this->assertNull($item['resolved']['price_policy']['combo_external_total']);
		}
	}

	public function test_composite_sku_quantity_multiplied_into_combo_external_total(): void
	{
		$result = $this->makeService()->normalizeOrderPayload([
			'id'         => 'ord-5',
			'store_id'   => 's1',
			'status'     => 'pending',
			'short_id'   => 5,
			'cart_items' => [
				[
					'id'       => 'line-1',
					'price'    => 200.00,
					'quantity' => 3,
					'product'  => ['id' => 'p1', 'name' => 'Combo', 'sku' => 'SKU-X+SKU-Y', 'slug' => 'combo', 'thumb' => null, 'images' => []],
					'variant'  => ['id' => null, 'taager_code' => null, 'variation_props' => []],
				],
			],
		], 'ord-5');

		// combo_external_total = 200 × 3 = 600
		foreach ($result['normalized']['items'] as $item) {
			$this->assertSame(600.0, $item['resolved']['price_policy']['combo_external_total']);
		}
	}
}
