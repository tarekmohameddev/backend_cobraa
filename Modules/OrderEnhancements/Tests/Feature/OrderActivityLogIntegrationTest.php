<?php
declare(strict_types=1);

namespace Modules\OrderEnhancements\Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService\OrderService;
use App\Services\OrderService\OrderStatusUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\OrderEnhancements\Entities\OrderActivityLog;
use Modules\OrderEnhancements\Services\OrderActivityLogService;
use App\Models\Language;
use Tests\TestCase;

class OrderActivityLogIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        if (!Language::query()->where('default', 1)->exists()) {
            Language::query()->create([
                'locale' => 'en',
                'default' => 1,
            ]);
        }
    }

    public function test_activity_logs_endpoint_returns_paginated_logs(): void
    {
        $employee = $this->createUser(['firstname' => 'Emp', 'lastname' => 'Viewer']);
        Sanctum::actingAs($employee, ['*']);

        $owner = $this->createUser(['email' => 'owner_' . Str::random(10) . '@example.com']);
        $shopId = $this->createShop($owner->id);
        $order = $this->createOrder($shopId);

        DB::table('order_activity_logs')->insert([
            'order_id' => $order->id,
            'user_id' => $employee->id,
            'activity_type' => 'items_modified',
            'description' => 'Order items modified',
            'lines_added' => 1,
            'units_added' => 2,
            'lines_removed' => 0,
            'units_removed' => 0,
            'metadata' => json_encode(['details' => []]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/dashboard/admin/orders/{$order->id}/activity-logs?per_page=10");
        $response->assertStatus(200);
        $response->assertJsonPath('status', true);

        $payload = $response->json();
        $rows = data_get($payload, 'data.data', data_get($payload, 'data'));

        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame($order->id, (int) $rows[0]['order_id']);
        $this->assertSame('items_modified', (string) $rows[0]['activity_type']);
        $this->assertSame(1, (int) $rows[0]['lines_added']);
        $this->assertSame(2, (int) $rows[0]['units_added']);
    }

    public function test_order_update_logs_item_diff(): void
    {
        $employee = $this->createUser(['firstname' => 'Emp', 'lastname' => 'Edit']);
        Sanctum::actingAs($employee, ['*']);

        $owner = $this->createUser(['email' => 'owner_' . Str::random(10) . '@example.com']);
        $shopId = $this->createShop($owner->id);

        $productId = $this->createProduct($shopId);
        $stockA = $this->createStock($productId, sku: 'A-' . Str::random(6), quantity: 100);
        $stockB = $this->createStock($productId, sku: 'B-' . Str::random(6), quantity: 100);
        $stockC = $this->createStock($productId, sku: 'C-' . Str::random(6), quantity: 100);

        $order = $this->createOrder($shopId);

        $this->createOrderDetail($order->id, $stockA, quantity: 1);
        $this->createOrderDetail($order->id, $stockB, quantity: 1);

        /** @var OrderService $service */
        $service = app(OrderService::class);

        $result = $service->update($order->id, [
            'products' => [
                ['stock_id' => $stockA, 'quantity' => 2],
                ['stock_id' => $stockC, 'quantity' => 3],
            ],
        ]);

        if (!(bool) data_get($result, 'status')) {
            $this->fail('OrderService::update failed: ' . json_encode($result));
        }

        /** @var OrderActivityLog $log */
        $log = OrderActivityLog::query()
            ->where('order_id', $order->id)
            ->where('activity_type', OrderActivityLogService::TYPE_ITEMS_MODIFIED)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(1, $log->lines_added);   // C added
        $this->assertSame(1, $log->lines_removed); // B removed
        $this->assertSame(4, $log->units_added);   // +1 on A, +3 on C
        $this->assertSame(1, $log->units_removed); // -1 on B
        $this->assertSame($employee->id, $log->user_id);
    }

    public function test_status_change_logs_activity(): void
    {
        $employee = $this->createUser(['firstname' => 'Emp', 'lastname' => 'Status']);
        Sanctum::actingAs($employee, ['*']);

        $owner = $this->createUser(['email' => 'owner_' . Str::random(10) . '@example.com']);
        $shopId = $this->createShop($owner->id);
        $order = $this->createOrder($shopId, ['status' => Order::STATUS_NEW]);

        /** @var OrderStatusUpdateService $service */
        $service = app(OrderStatusUpdateService::class);

        $result = $service->statusUpdate($order, ['status' => Order::STATUS_ACCEPTED], false);

        $this->assertTrue((bool) data_get($result, 'status'));

        $this->assertTrue(
            OrderActivityLog::query()
                ->where('order_id', $order->id)
                ->where('activity_type', OrderActivityLogService::TYPE_STATUS_CHANGED)
                ->where('user_id', $employee->id)
                ->exists()
        );
    }

    private function createUser(array $overrides = []): User
    {
        $id = DB::table('users')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'firstname' => 'Test',
            'lastname' => 'User',
            'email' => 'user_' . Str::random(10) . '@example.com',
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return User::query()->findOrFail((int) $id);
    }

    private function createShop(int $ownerUserId): int
    {
        return (int) DB::table('shops')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'user_id' => $ownerUserId,
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

    private function createProduct(int $shopId): int
    {
        return (int) DB::table('products')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'shop_id' => $shopId,
            'category_id' => null,
            'brand_id' => null,
            'unit_id' => null,
            'keywords' => null,
            'img' => null,
            'qr_code' => null,
            'tax' => 0,
            'active' => 1,
            'status' => Product::PUBLISHED,
            'min_qty' => 1,
            'max_qty' => 1000,
            'digital' => 0,
            'age_limit' => 0,
            'visibility' => 1,
            'interval' => 1,
            'status_note' => null,
            'r_count' => 0,
            'r_avg' => 0,
            'r_sum' => 0,
            'o_count' => 0,
            'od_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createStock(int $productId, string $sku, int $quantity): int
    {
        return (int) DB::table('stocks')->insertGetId([
            'product_id' => $productId,
            'price' => 10,
            'quantity' => $quantity,
            'bonus_expired_at' => null,
            'discount_expired_at' => null,
            'sku' => $sku,
            'discount_id' => null,
            'tax' => null,
            'img' => null,
            'o_count' => 0,
            'od_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOrder(int $shopId, array $overrides = []): Order
    {
        $id = DB::table('orders')->insertGetId(array_merge([
            'type' => '1',
            'user_id' => null,
            'shop_id' => $shopId,
            'parent_id' => null,
            'deliveryman_id' => null,
            'currency_id' => null,
            'delivery_price_id' => null,
            'delivery_point_id' => null,
            'address_id' => null,
            'status' => Order::STATUS_NEW,
            'total_price' => 0,
            'commission_fee' => 0,
            'service_fee' => 0,
            'delivery_fee' => 0,
            'total_discount' => 0,
            'total_tax' => 1,
            'rate' => 1,
            'note' => null,
            'location' => null,
            'address' => null,
            'phone' => null,
            'username' => null,
            'delivery_date' => null,
            'delivery_type' => Order::POINT,
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
        ], $overrides));

        return Order::query()->findOrFail((int) $id);
    }

    private function createOrderDetail(int $orderId, int $stockId, int $quantity): void
    {
        DB::table('order_details')->insert([
            'order_id' => $orderId,
            'stock_id' => $stockId,
            'replace_stock_id' => null,
            'replace_quantity' => null,
            'replace_note' => null,
            'origin_price' => 10 * $quantity,
            'total_price' => 10 * $quantity,
            'tax' => 0,
            'discount' => 0,
            'quantity' => $quantity,
            'bonus' => 0,
            'note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

