<?php

declare(strict_types=1);

namespace Modules\Stox\Tests\Unit;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Stox\Entities\StoxAccount;
use Modules\Stox\Services\StoxOrderExportService;
use Tests\TestCase;

/**
 * Tests that the mobile_2 field in the Stox payload uses the correct priority:
 * override_data.mobile_2 > order.phone_alt > account settings.mobile_2
 */
class StoxOrderExportMobile2Test extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): int
    {
        return (int) DB::table('users')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'firstname' => 'Test',
            'lastname' => 'User',
            'email' => 'u_' . \Illuminate\Support\Str::random(8) . '@test.com',
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeShop(): int
    {
        $userId = $this->makeUser();

        return (int) DB::table('shops')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $userId,
            'tax' => 0,
            'percentage' => 0,
            'lat_long' => json_encode(['latitude' => '0', 'longitude' => '0']),
            'phone' => '0000',
            'open' => 1,
            'visibility' => 1,
            'background_img' => null,
            'logo_img' => null,
            'min_amount' => 0.1,
            'status' => 'approved',
            'status_note' => null,
            'delivery_time' => json_encode(['from' => '09:00', 'to' => '18:00']),
            'type' => 1,
            'verify' => 1,
            'r_count' => 0,
            'r_avg' => 0,
            'r_sum' => 0,
            'o_count' => 0,
            'od_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeOrder(array $attributes = []): Order
    {
        $shopId = $this->makeShop();

        $id = DB::table('orders')->insertGetId(array_merge([
            'type' => 1,
            'user_id' => null,
            'shop_id' => $shopId,
            'parent_id' => null,
            'deliveryman_id' => null,
            'currency_id' => null,
            'delivery_price_id' => null,
            'delivery_point_id' => null,
            'address_id' => null,
            'status' => Order::STATUS_NEW,
            'total_price' => 100,
            'commission_fee' => 0,
            'service_fee' => 0,
            'delivery_fee' => 0,
            'total_discount' => 0,
            'total_tax' => 0,
            'rate' => 1,
            'note' => null,
            'location' => null,
            'address' => json_encode(['address' => 'Test St']),
            'phone' => '01000000000',
            'phone_alt' => null,
            'username' => 'Test User',
            'delivery_date' => null,
            'delivery_type' => Order::DELIVERY,
            'img' => null,
            'canceled_note' => null,
            'track_name' => null,
            'track_id' => null,
            'track_url' => null,
            'current' => 0,
            'coupon_price' => 0,
            'cart_id' => null,
            'tips' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return Order::query()->with(['orderDetails', 'user', 'myAddress'])->findOrFail((int) $id);
    }

    private function makeAccount(array $settings = []): StoxAccount
    {
        return StoxAccount::query()->make([
            'name' => 'Test Account',
            'bearer_token' => 'token',
            'base_url' => 'https://stox.test',
            'shop_ids' => [],
            'settings' => $settings,
            'status' => 'active',
        ]);
    }

    /** Minimal override_data to satisfy buildProductPayload without real order details. */
    private function dummyProducts(): array
    {
        return ['products' => [['sku' => 'TEST-SKU', 'qty' => 1]]];
    }

    private function buildPayload(Order $order, StoxAccount $account, array $overrideData): array
    {
        $service = app(StoxOrderExportService::class);
        $reflection = new \ReflectionMethod($service, 'buildPayload');
        $reflection->setAccessible(true);
        return $reflection->invoke($service, $order, $account, $overrideData);
    }

    public function test_mobile_2_uses_order_phone_alt_when_set(): void
    {
        $order = $this->makeOrder(['phone_alt' => '01099999999']);
        $account = $this->makeAccount(['mobile_2' => '01011111111']);

        $payload = $this->buildPayload($order, $account, $this->dummyProducts());

        $this->assertSame('01099999999', $payload['mobile_2']);
    }

    public function test_mobile_2_falls_back_to_account_settings_when_phone_alt_empty(): void
    {
        $order = $this->makeOrder(['phone_alt' => null]);
        $account = $this->makeAccount(['mobile_2' => '01011111111']);

        $payload = $this->buildPayload($order, $account, $this->dummyProducts());

        $this->assertSame('01011111111', $payload['mobile_2']);
    }

    public function test_mobile_2_override_takes_highest_priority(): void
    {
        $order = $this->makeOrder(['phone_alt' => '01099999999']);
        $account = $this->makeAccount(['mobile_2' => '01011111111']);

        $payload = $this->buildPayload($order, $account, array_merge(
            $this->dummyProducts(),
            ['mobile_2' => '01055555555']
        ));

        $this->assertSame('01055555555', $payload['mobile_2']);
    }

    public function test_mobile_2_absent_when_all_sources_empty(): void
    {
        $order = $this->makeOrder(['phone_alt' => null]);
        $account = $this->makeAccount([]);

        $payload = $this->buildPayload($order, $account, $this->dummyProducts());

        $this->assertArrayNotHasKey('mobile_2', $payload);
    }
}
