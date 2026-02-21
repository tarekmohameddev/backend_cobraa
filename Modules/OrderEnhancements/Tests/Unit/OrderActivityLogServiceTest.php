<?php
declare(strict_types=1);

namespace Modules\OrderEnhancements\Tests\Unit;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\OrderEnhancements\Entities\OrderActivityLog;
use Modules\OrderEnhancements\Services\OrderActivityLogService;
use Tests\TestCase;

class OrderActivityLogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_creation_creates_activity_record(): void
    {
        $actor = $this->createUser(['firstname' => 'Emp', 'lastname' => 'One']);
        $order = $this->createOrder();

        (new OrderActivityLogService())->logCreation($order, $actor);

        $this->assertTrue(
            OrderActivityLog::query()
                ->where('order_id', $order->id)
                ->where('user_id', $actor->id)
                ->where('activity_type', OrderActivityLogService::TYPE_CREATED)
                ->exists()
        );
    }

    public function test_log_items_modified_stores_correct_counts(): void
    {
        $actor = $this->createUser();
        $order = $this->createOrder();

        (new OrderActivityLogService())->logItemsModified(
            $order,
            $actor,
            linesAdded: 2,
            unitsAdded: 7,
            linesRemoved: 1,
            unitsRemoved: 3,
            details: ['added' => [['stock_id' => 10, 'quantity' => 5]]],
        );

        /** @var OrderActivityLog $log */
        $log = OrderActivityLog::query()
            ->where('order_id', $order->id)
            ->where('activity_type', OrderActivityLogService::TYPE_ITEMS_MODIFIED)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(2, $log->lines_added);
        $this->assertSame(7, $log->units_added);
        $this->assertSame(1, $log->lines_removed);
        $this->assertSame(3, $log->units_removed);
        $this->assertIsArray($log->metadata);
        $this->assertSame(10, data_get($log->metadata, 'details.added.0.stock_id'));
    }

    public function test_log_status_change_records_from_and_to(): void
    {
        $actor = $this->createUser();
        $order = $this->createOrder(['status' => Order::STATUS_NEW]);

        (new OrderActivityLogService())->logStatusChange($order, $actor, Order::STATUS_NEW, Order::STATUS_ACCEPTED);

        /** @var OrderActivityLog $log */
        $log = OrderActivityLog::query()
            ->where('order_id', $order->id)
            ->where('activity_type', OrderActivityLogService::TYPE_STATUS_CHANGED)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(Order::STATUS_NEW, data_get($log->metadata, 'from'));
        $this->assertSame(Order::STATUS_ACCEPTED, data_get($log->metadata, 'to'));
    }

    public function test_log_deliveryman_assigned_records_deliveryman_info(): void
    {
        $actor = $this->createUser(['firstname' => 'Manager']);
        $deliveryman = $this->createUser(['firstname' => 'Driver', 'lastname' => 'One']);
        $order = $this->createOrder();

        (new OrderActivityLogService())->logDeliverymanAssigned($order, $actor, $deliveryman);

        /** @var OrderActivityLog $log */
        $log = OrderActivityLog::query()
            ->where('order_id', $order->id)
            ->where('activity_type', OrderActivityLogService::TYPE_DELIVERYMAN_ASSIGNED)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($deliveryman->id, data_get($log->metadata, 'deliveryman_id'));
        $this->assertSame('Driver One', trim((string) data_get($log->metadata, 'deliveryman_name')));
    }

    public function test_logging_failure_does_not_throw(): void
    {
        $actor = $this->createUser();

        $fakeOrder = new Order();
        $fakeOrder->id = 999999999;

        $beforeCount = OrderActivityLog::query()->count();

        (new OrderActivityLogService())->logCreation($fakeOrder, $actor);

        $this->assertSame($beforeCount, OrderActivityLog::query()->count());
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

    private function createOrder(array $overrides = []): Order
    {
        $owner = $this->createUser(['email' => 'owner_' . Str::random(10) . '@example.com']);
        $shopId = $this->createShop($owner->id);

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
}

