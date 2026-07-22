<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Modules\EasyOrders\Services\WebhookService;
use Tests\TestCase;

class WebhookServicePhoneAltTest extends TestCase
{
	public function test_normalize_order_payload_includes_phone_alt(): void
	{
		Config::set('easyorders.enabled', false);

		$service = app(WebhookService::class);

		$payload = [
			'id' => 'order-1',
			'store_id' => 'store-1',
			'status' => 'pending',
			'short_id' => 1,
			'full_name' => 'Test User',
			'phone' => '01000000000',
			'phone_alt' => '01099999999',
			'address' => 'Test St',
			'cart_items' => [],
		];

		$result = $service->normalizeOrderPayload($payload, 'order-1');

		$this->assertSame('01000000000', data_get($result, 'normalized.customer.phone'));
		$this->assertSame('01099999999', data_get($result, 'normalized.customer.phone_alt'));
	}

	public function test_normalize_order_payload_handles_missing_phone_alt(): void
	{
		Config::set('easyorders.enabled', false);

		$service = app(WebhookService::class);

		$payload = [
			'id' => 'order-2',
			'store_id' => 'store-1',
			'status' => 'pending',
			'short_id' => 2,
			'full_name' => 'Test User',
			'phone' => '01000000000',
			'cart_items' => [],
		];

		$result = $service->normalizeOrderPayload($payload, 'order-2');

		$this->assertNull(data_get($result, 'normalized.customer.phone_alt'));
	}
}
