<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\EasyOrders\Entities\EasyOrdersStore;
use Modules\EasyOrders\Entities\EasyOrdersTempOrder;
use Modules\EasyOrders\Entities\EasyOrdersWebhookLog;
use Modules\EasyOrders\Jobs\ProcessWebhookJob;
use Modules\EasyOrders\Jobs\ValidateTempOrderJob;
use Modules\EasyOrders\Services\WebhookService;
use Tests\TestCase;

class EasyOrdersWebhookTest extends TestCase
{
	use RefreshDatabase;
	use WithoutMiddleware;

	public function test_webhook_requires_secret(): void
	{
		// Include required payload so request validation doesn't throw.
		$response = $this->postJson('/api/v1/integrations/easyorders/webhook', ['id' => 'ext-0']);
		$response->assertStatus(401);
	}

	public function test_webhook_accepts_valid_secret(): void
	{
		config(['easyorders.auto_import_validated' => false]);

		$store = EasyOrdersStore::query()->create([
			'name' => 'Test',
			'webhook_secret' => 'secret-token',
			'api_key' => 'store-api-token',
			'status' => 'active',
		]);

		$externalOrderId = 'ext-1';

		Queue::fake();

		Http::fake([
			'https://api.easy-orders.net/api/v1/external-apps/orders/*' => Http::response([
				'id' => $externalOrderId,
				'store_id' => 'store-1',
				'status' => 'pending',
				'short_id' => 123,
				'cart_items' => [],
				'full_name' => 'John Doe',
				'phone' => '01000000000',
				'address' => "Line 1\nLine 2",
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

		$webhookLogId = $response->json('webhook_log_id');
		$this->assertNotNull($webhookLogId);

		/** @var EasyOrdersWebhookLog|null $log */
		$log = EasyOrdersWebhookLog::query()->find((int) $webhookLogId);
		$this->assertNotNull($log);
		$this->assertSame($store->id, $log->store_id);
		$this->assertSame($externalOrderId, $log->external_order_id);
		$this->assertSame('received', $log->processing_status);

		Queue::assertPushed(ProcessWebhookJob::class);

		// Run processing job inline for test
		(new ProcessWebhookJob((int) $log->id))->handle(app(WebhookService::class));

		$log->refresh();
		$this->assertSame('processed', $log->processing_status);

		// Ensure a temp order was created by processing job
		/** @var EasyOrdersTempOrder $temp */
		$temp = EasyOrdersTempOrder::query()->first();
		$this->assertNotNull($temp);

		// Run validation job inline for test
		(new ValidateTempOrderJob($temp->id))->handle(app(\Modules\EasyOrders\Services\ValidationService::class));
		$temp->refresh();
		$this->assertSame('validated', $temp->status);

		// Assert webhook-derived fields landed in temp order
		$this->assertSame('John Doe', $temp->customer_name);
		$this->assertSame('01000000000', $temp->customer_phone);
		$this->assertSame("Line 1\nLine 2", $temp->address);
	}

	public function test_webhook_returns_200_even_if_easyorders_api_fails(): void
	{
		$store = EasyOrdersStore::query()->create([
			'name' => 'Test',
			'webhook_secret' => 'secret-token',
			'api_key' => 'store-api-token',
			'status' => 'active',
		]);

		Queue::fake();

		// Even if EasyOrders API is down, webhook endpoint should still return 200,
		// because it does not call EasyOrders API inline anymore.
		Http::fake([
			'*' => Http::response(['message' => 'down'], 500),
		]);

		$payload = [
			'id' => 'ext-2',
			'store_id' => 'store-1',
			'status' => 'pending',
			'short_id' => 123,
			'cart_items' => [],
		];

		$response = $this->withHeaders(['secret' => 'secret-token'])
			->postJson('/api/v1/integrations/easyorders/webhook', $payload);

		$response->assertStatus(200);

		/** @var EasyOrdersWebhookLog|null $log */
		$log = EasyOrdersWebhookLog::query()->where('store_id', $store->id)->where('external_order_id', 'ext-2')->first();
		$this->assertNotNull($log);
		$this->assertSame('received', $log->processing_status);
	}

	public function test_webhook_captures_phone_alt(): void
	{
		config(['easyorders.auto_import_validated' => false]);

		$store = EasyOrdersStore::query()->create([
			'name' => 'Test',
			'webhook_secret' => 'secret-token',
			'api_key' => 'store-api-token',
			'status' => 'active',
		]);

		$externalOrderId = 'ext-phone-alt';

		Queue::fake();

		Http::fake([
			'https://api.easy-orders.net/api/v1/external-apps/orders/*' => Http::response([
				'id' => $externalOrderId,
				'store_id' => 'store-1',
				'status' => 'pending',
				'short_id' => 555,
				'cart_items' => [],
				'full_name' => 'Test User',
				'phone' => '01000000000',
				'phone_alt' => '01099999999',
				'address' => 'Test Address',
			], 200),
		]);

		$payload = [
			'id' => $externalOrderId,
			'store_id' => 'store-1',
			'status' => 'pending',
			'short_id' => 555,
			'cart_items' => [],
		];

		$response = $this->withHeaders(['secret' => 'secret-token'])
			->postJson('/api/v1/integrations/easyorders/webhook', $payload);
		$response->assertStatus(200);

		$webhookLogId = $response->json('webhook_log_id');
		$log = EasyOrdersWebhookLog::query()->find((int) $webhookLogId);
		$this->assertNotNull($log);

		(new ProcessWebhookJob((int) $log->id))->handle(app(WebhookService::class));

		/** @var EasyOrdersTempOrder $temp */
		$temp = EasyOrdersTempOrder::query()->where('external_order_id', $externalOrderId)->first();
		$this->assertNotNull($temp);
		$this->assertSame('01000000000', $temp->customer_phone);
		$this->assertSame('01099999999', $temp->customer_phone_alt);

		// Verify phone_alt is in the normalized snapshot
		$this->assertSame('01099999999', data_get($temp->normalized, 'customer.phone_alt'));
	}
}


