<?php
declare(strict_types=1);

namespace Modules\Stox\Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoxEmployeeExportReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_includes_lines_and_units_added(): void
    {
        $employee = $this->createUser(['firstname' => 'Emp', 'lastname' => 'Report']);
        Sanctum::actingAs($employee, ['*']);

        $owner = $this->createUser(['email' => 'owner_' . Str::random(10) . '@example.com']);
        $shopId = $this->createShop($owner->id);
        $order = $this->createOrder($shopId);

        DB::table('order_activity_logs')->insert([
            'order_id' => $order->id,
            'user_id' => $employee->id,
            'activity_type' => 'items_modified',
            'description' => 'Order items modified',
            'lines_added' => 3,
            'units_added' => 11,
            'lines_removed' => 0,
            'units_removed' => 0,
            'metadata' => json_encode(['details' => []]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/dashboard/admin/stox/reports/employee-export-counts');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertIsArray($data);

        $row = collect($data)->firstWhere('user_id', $employee->id);
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['orders_exported_count']);
        $this->assertSame(3, (int) $row['total_lines_added']);
        $this->assertSame(11, (int) $row['total_units_added']);
    }

    public function test_report_shows_employee_with_only_item_edits(): void
    {
        $employee = $this->createUser(['firstname' => 'Emp', 'lastname' => 'OnlyEdits']);
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
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/dashboard/admin/stox/reports/employee-export-counts');
        $response->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('user_id', $employee->id);
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['orders_exported_count']);
        $this->assertSame(1, (int) $row['total_lines_added']);
        $this->assertSame(2, (int) $row['total_units_added']);
    }

    public function test_report_date_filters_apply_to_activity_logs(): void
    {
        $employee = $this->createUser(['firstname' => 'Emp', 'lastname' => 'Dates']);
        Sanctum::actingAs($employee, ['*']);

        $owner = $this->createUser(['email' => 'owner_' . Str::random(10) . '@example.com']);
        $shopId = $this->createShop($owner->id);
        $order = $this->createOrder($shopId);

        DB::table('order_activity_logs')->insert([
            'order_id' => $order->id,
            'user_id' => $employee->id,
            'activity_type' => 'items_modified',
            'description' => 'Order items modified',
            'lines_added' => 5,
            'units_added' => 5,
            'lines_removed' => 0,
            'units_removed' => 0,
            'metadata' => json_encode([]),
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        $from = now()->subDays(2)->format('Y-m-d');
        $to = now()->format('Y-m-d');

        $response = $this->getJson('/api/v1/dashboard/admin/stox/reports/employee-export-counts?date_from=' . $from . '&date_to=' . $to);
        $response->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('user_id', $employee->id);
        $this->assertNull($row);
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
}

