<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Tests\Feature;

use App\Models\Order;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\EasyOrders\Entities\EasyOrdersStore;
use Modules\EasyOrders\Entities\EasyOrdersTempOrder;
use Modules\EasyOrders\Jobs\ImportTempOrderJob;
use Tests\TestCase;

class EasyOrdersWebhookTest extends TestCase
{
	use RefreshDatabase;

	public function test_webhook_requires_secret(): void
	{
		$response = $this->postJson('/api/v1/integrations/easyorders/webhook', []);
		$response->assertStatus(401);
	}

	public function test_webhook_accepts_valid_secret(): void
	{
		$store = EasyOrdersStore::query()->create([
			'name' => 'Test',
			'webhook_secret' => 'secret-token',
			'api_key' => 'store-api-token',
			'status' => 'active',
		]);

		$externalOrderId = 'ext-1';

		Http::fake([
			'https://api.easy-orders.net/api/v1/external-apps/orders/*' => Http::response([
				'id' => $externalOrderId,
				'store_id' => 'store-1',
				'status' => 'pending',
				'short_id' => 123,
				'cart_items' => [],
				'customer' => [
					'full_name' => 'John Doe',
					'phone' => '01000000000',
					'address' => "Line 1\nLine 2",
				],
			], 200),
		]);

		$payload = [
			'id' => $externalOrderId,
			'store_id' => 'store-1',
			'status' => 'pending',
			'short_id' => 123,
			'cart_items' => [],
		];
		$response = $this->withHeaders(['secret' => 'secret-token'])
			->postJson('/api/v1/integrations/easyorders/webhook', $payload);
		$response->assertStatus(200);

		// Ensure a temp order was created
		/** @var EasyOrdersTempOrder $temp */
		$temp = EasyOrdersTempOrder::query()->first();
		$this->assertNotNull($temp);

		// Manually run import job to create internal orders
		(new ImportTempOrderJob($temp->id))->handle(app(\Modules\EasyOrders\Services\ImportService::class));

		/** @var Order $order */
		$order = Order::query()->first();
		$this->assertNotNull($order);

		// Assert nested address JSON: {"address": {"address": "..."}}
		$address = $order->address;
		$this->assertIsArray($address);
		$this->assertArrayHasKey('address', $address);
		$this->assertIsArray($address['address']);
		$this->assertSame("Line 1\nLine 2", $address['address']['address'] ?? null);

		// Assert a UserAddress was created and linked via address_id
		$this->assertNotNull($order->address_id);
		/** @var UserAddress $userAddress */
		$userAddress = UserAddress::query()->find($order->address_id);
		$this->assertNotNull($userAddress);
		$this->assertSame($order->user_id, $userAddress->user_id);
		$this->assertSame(
			['address' => "Line 1\nLine 2"],
			$userAddress->address
		);
	}
}


