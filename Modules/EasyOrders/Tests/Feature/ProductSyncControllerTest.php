<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Tests\Feature;

use App\Models\Language;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\EasyOrders\Entities\EasyOrdersStore;
use Tests\TestCase;

class ProductSyncControllerTest extends TestCase
{
	use RefreshDatabase;

	public function test_admin_can_trigger_single_product_sync(): void
	{
		Language::query()->create([
			'locale'  => 'en',
			'default' => 1,
		]);

		$shop = Shop::factory()->create();
		Config::set('easyorders.main_shop_id', $shop->id);

		$store = EasyOrdersStore::query()->create([
			'name'           => 'Test Store',
			'webhook_secret' => 'secret',
			'api_key'        => 'api-key',
			'status'         => 'active',
		]);

		$externalProductId = 'p-1';

		Http::fake([
			'https://api.easy-orders.net/api/v1/external-apps/products/' . $externalProductId => Http::response([
				'id'          => $externalProductId,
				'name'        => 'Controller Product',
				'price'       => 500,
				'sale_price'  => 450,
				'description' => '<p>Test</p>',
				'slug'        => 'controller-product',
				'sku'         => 'CP-1',
				'thumb'       => 'https://example.com/thumb.jpg',
				'images'      => [],
				'quantity'    => 10,
				'track_stock' => true,
				'categories'  => [],
				'variations'  => [],
				'variants'    => [],
			], 200),
		]);

		// Authenticate as admin via Sanctum (adjust as per your auth setup)
		$admin = User::factory()->create();
		Sanctum::actingAs($admin, ['*']);

		$response = $this->postJson("/api/v1/dashboard/admin/easyorders/products/{$externalProductId}/sync");
		$response->assertStatus(200);
		$response->assertJsonFragment(['sku' => 'CP-1']);
	}
}


