<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\EasyOrders\Entities\EasyOrdersStore;
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

		Http::fake([
			'https://api.easy-orders.net/api/v1/external-apps/orders/*' => Http::response([
				'id' => 'ext-1',
				'store_id' => 'store-1',
				'status' => 'pending',
				'short_id' => 123,
				'cart_items' => [],
			], 200),
		]);

		$payload = [
			'id' => 'ext-1',
			'store_id' => 'store-1',
			'status' => 'pending',
			'short_id' => 123,
			'cart_items' => [],
		];
		$response = $this->withHeaders(['secret' => 'secret-token'])
			->postJson('/api/v1/integrations/easyorders/webhook', $payload);
		$response->assertStatus(200);
	}
}


