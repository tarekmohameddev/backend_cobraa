<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Tests\Unit;

use App\Models\Order as OrderModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\EasyOrders\Services\ImportService;
use Modules\EasyOrders\Services\StockResolver;
use Tests\TestCase;

/**
 * Tests for ImportService private price-discount helpers.
 *
 * We test via reflection so the business logic is verified in isolation, without
 * needing a full order-import flow.
 */
class ImportServicePriceDiscountTest extends TestCase
{
	use RefreshDatabase;

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function makeService(): ImportService
	{
		return new ImportService(app(StockResolver::class));
	}

	private function callSumExternalPrices(ImportService $service, array $items): ?float
	{
		$method = new \ReflectionMethod($service, 'sumExternalPrices');
		$method->setAccessible(true);

		return $method->invoke($service, $items);
	}

	private function callApplyPriceDiscount(ImportService $service, array &$orderModels, float $discount): void
	{
		$method = new \ReflectionMethod($service, 'applyPriceDiscount');
		$method->setAccessible(true);
		$method->invokeArgs($service, [&$orderModels, $discount]);
	}

	private function callCalculateExternalPriceDiscount(ImportService $service, array $items, array &$orderModels): void
	{
		$method = new \ReflectionMethod($service, 'calculateExternalPriceDiscount');
		$method->setAccessible(true);
		$method->invokeArgs($service, [$items, &$orderModels]);
	}

	/** Build a minimal order row and return a fresh OrderModel. */
	private function makeOrder(float $totalPrice, float $totalDiscount = 0): OrderModel
	{
		$userId = DB::table('users')->insertGetId([
			'uuid'       => (string) \Illuminate\Support\Str::uuid(),
			'firstname'  => 'Test',
			'lastname'   => 'User',
			'email'      => 'u_' . \Illuminate\Support\Str::random(8) . '@test.com',
			'active'     => 1,
			'created_at' => now(),
			'updated_at' => now(),
		]);

		$shopUserId = DB::table('users')->insertGetId([
			'uuid'       => (string) \Illuminate\Support\Str::uuid(),
			'firstname'  => 'Shop',
			'lastname'   => 'Owner',
			'email'      => 'shop_' . \Illuminate\Support\Str::random(8) . '@test.com',
			'active'     => 1,
			'created_at' => now(),
			'updated_at' => now(),
		]);

		$shopId = DB::table('shops')->insertGetId([
			'uuid'           => (string) \Illuminate\Support\Str::uuid(),
			'user_id'        => $shopUserId,
			'tax'            => 0,
			'percentage'     => 0,
			'lat_long'       => json_encode(['latitude' => '0', 'longitude' => '0']),
			'phone'          => '0000',
			'open'           => 1,
			'visibility'     => 1,
			'background_img' => null,
			'logo_img'       => null,
			'min_amount'     => 0.1,
			'status'         => 'approved',
			'status_note'    => null,
			'delivery_time'  => json_encode(['from' => '09:00', 'to' => '18:00']),
			'type'           => 1,
			'verify'         => 1,
			'r_count'        => 0,
			'r_avg'          => 0,
			'r_sum'          => 0,
			'o_count'        => 0,
			'od_count'       => 0,
			'created_at'     => now(),
			'updated_at'     => now(),
		]);

		$id = DB::table('orders')->insertGetId([
			'type'               => 1,
			'user_id'            => $userId,
			'shop_id'            => $shopId,
			'parent_id'          => null,
			'deliveryman_id'     => null,
			'currency_id'        => null,
			'delivery_price_id'  => null,
			'delivery_point_id'  => null,
			'address_id'         => null,
			'status'             => OrderModel::STATUS_NEW,
			'total_price'        => $totalPrice,
			'commission_fee'     => 0,
			'service_fee'        => 0,
			'delivery_fee'       => 0,
			'total_discount'     => $totalDiscount,
			'total_tax'          => 0,
			'rate'               => 1,
			'note'               => null,
			'location'           => null,
			'address'            => json_encode(['address' => 'Test St']),
			'phone'              => '01000000000',
			'phone_alt'          => null,
			'username'           => 'Test User',
			'delivery_date'      => null,
			'delivery_type'      => OrderModel::DELIVERY,
			'img'                => null,
			'canceled_note'      => null,
			'track_name'         => null,
			'track_id'           => null,
			'track_url'          => null,
			'current'            => 0,
			'coupon_price'       => 0,
			'cart_id'            => null,
			'tips'               => 0,
			'created_at'         => now(),
			'updated_at'         => now(),
		]);

		return OrderModel::findOrFail((int) $id);
	}

	// -----------------------------------------------------------------------
	// sumExternalPrices()
	// -----------------------------------------------------------------------

	public function test_sum_external_prices_returns_null_when_no_prices(): void
	{
		$service = $this->makeService();

		$items = [
			['resolved' => ['price_policy' => ['external_line_total' => null]]],
			['resolved' => ['price_policy' => ['external_line_total' => null]]],
		];

		$this->assertNull($this->callSumExternalPrices($service, $items));
	}

	public function test_sum_external_prices_sums_regular_items(): void
	{
		$service = $this->makeService();

		$items = [
			['resolved' => ['price_policy' => ['external_line_total' => 100.0, 'combo_group_id' => null]]],
			['resolved' => ['price_policy' => ['external_line_total' => 250.0, 'combo_group_id' => null]]],
		];

		$this->assertSame(350.0, $this->callSumExternalPrices($service, $items));
	}

	public function test_sum_external_prices_counts_combo_total_once(): void
	{
		$service = $this->makeService();

		// Three split parts sharing the same group; combo total is 950.
		$items = [
			['resolved' => ['price_policy' => ['external_line_total' => null, 'combo_group_id' => 'g1', 'combo_external_total' => 950.0]]],
			['resolved' => ['price_policy' => ['external_line_total' => null, 'combo_group_id' => 'g1', 'combo_external_total' => 950.0]]],
			['resolved' => ['price_policy' => ['external_line_total' => null, 'combo_group_id' => 'g1', 'combo_external_total' => 950.0]]],
		];

		$this->assertSame(950.0, $this->callSumExternalPrices($service, $items));
	}

	public function test_sum_external_prices_handles_mixed_regular_and_combo(): void
	{
		$service = $this->makeService();

		$items = [
			// Regular item: 200
			['resolved' => ['price_policy' => ['external_line_total' => 200.0, 'combo_group_id' => null]]],
			// Combo group (2 parts, total 950)
			['resolved' => ['price_policy' => ['external_line_total' => null, 'combo_group_id' => 'gA', 'combo_external_total' => 950.0]]],
			['resolved' => ['price_policy' => ['external_line_total' => null, 'combo_group_id' => 'gA', 'combo_external_total' => 950.0]]],
			// Another regular item: 100
			['resolved' => ['price_policy' => ['external_line_total' => 100.0, 'combo_group_id' => null]]],
		];

		// 200 + 950 + 100 = 1250
		$this->assertSame(1250.0, $this->callSumExternalPrices($service, $items));
	}

	public function test_sum_external_prices_handles_multiple_distinct_combo_groups(): void
	{
		$service = $this->makeService();

		$items = [
			// Combo group 1 (2 parts, total 500)
			['resolved' => ['price_policy' => ['external_line_total' => null, 'combo_group_id' => 'g1', 'combo_external_total' => 500.0]]],
			['resolved' => ['price_policy' => ['external_line_total' => null, 'combo_group_id' => 'g1', 'combo_external_total' => 500.0]]],
			// Combo group 2 (2 parts, total 300)
			['resolved' => ['price_policy' => ['external_line_total' => null, 'combo_group_id' => 'g2', 'combo_external_total' => 300.0]]],
			['resolved' => ['price_policy' => ['external_line_total' => null, 'combo_group_id' => 'g2', 'combo_external_total' => 300.0]]],
		];

		$this->assertSame(800.0, $this->callSumExternalPrices($service, $items));
	}

	// -----------------------------------------------------------------------
	// applyPriceDiscount()
	// -----------------------------------------------------------------------

	public function test_apply_price_discount_on_single_order(): void
	{
		$service = $this->makeService();

		$order      = $this->makeOrder(1130.0, 0.0);
		$orderArray = [$order];

		$this->callApplyPriceDiscount($service, $orderArray, 180.0);

		$updated = $orderArray[0];
		$this->assertSame(950.0, (float) $updated->total_price);
		$this->assertSame(180.0, (float) $updated->total_discount);
	}

	public function test_apply_price_discount_distributes_proportionally_across_orders(): void
	{
		$service = $this->makeService();

		// Two orders totalling 1000; discount of 200 split 60/40.
		$order1     = $this->makeOrder(600.0);
		$order2     = $this->makeOrder(400.0);
		$orderArray = [$order1, $order2];

		$this->callApplyPriceDiscount($service, $orderArray, 200.0);

		$this->assertSame(480.0, (float) $orderArray[0]->total_price);  // 600 - 120
		$this->assertSame(120.0, (float) $orderArray[0]->total_discount);

		$this->assertSame(320.0, (float) $orderArray[1]->total_price);  // 400 - 80
		$this->assertSame(80.0,  (float) $orderArray[1]->total_discount);
	}

	public function test_apply_price_discount_does_not_reduce_below_zero(): void
	{
		$service = $this->makeService();

		$order      = $this->makeOrder(50.0);
		$orderArray = [$order];

		// Discount larger than order total — total_price should clamp to 0.
		$this->callApplyPriceDiscount($service, $orderArray, 200.0);

		$this->assertSame(0.0, (float) $orderArray[0]->total_price);
	}

	// -----------------------------------------------------------------------
	// calculateExternalPriceDiscount() — end-to-end helper
	// -----------------------------------------------------------------------

	public function test_calculate_does_nothing_when_no_external_prices(): void
	{
		$service = $this->makeService();

		$order      = $this->makeOrder(1130.0);
		$orderArray = [$order];

		$items = [
			['resolved' => ['price_policy' => ['external_line_total' => null]]],
		];

		$this->callCalculateExternalPriceDiscount($service, $items, $orderArray);

		// No change expected.
		$this->assertSame(1130.0, (float) $orderArray[0]->total_price);
		$this->assertSame(0.0,    (float) $orderArray[0]->total_discount);
	}

	public function test_calculate_does_nothing_when_catalog_price_equals_external(): void
	{
		$service = $this->makeService();

		$order      = $this->makeOrder(950.0);
		$orderArray = [$order];

		// External total also 950, so no discount.
		$items = [
			['resolved' => ['price_policy' => ['external_line_total' => null, 'combo_group_id' => 'g1', 'combo_external_total' => 950.0]]],
			['resolved' => ['price_policy' => ['external_line_total' => null, 'combo_group_id' => 'g1', 'combo_external_total' => 950.0]]],
		];

		// order_details sum must match total_price for this test; load empty collection.
		$order->setRelation('orderDetails', collect([]));

		$this->callCalculateExternalPriceDiscount($service, $items, $orderArray);

		$this->assertSame(950.0, (float) $orderArray[0]->total_price);
		$this->assertSame(0.0,   (float) $orderArray[0]->total_discount);
	}
}
