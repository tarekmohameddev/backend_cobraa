<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Tests\Unit;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Modules\EasyOrders\Entities\EasyOrdersStore;
use Modules\EasyOrders\Services\ProductSyncService;
use Tests\TestCase;

class ProductSyncServiceTest extends TestCase
{
	use RefreshDatabase;

	public function test_sync_one_creates_product_with_category_and_stock(): void
	{
		// Arrange: base data
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

		$externalProductId = '3c0e0038-8b4b-4417-9371-56b42c19356a';

		// Fake EasyOrders product response
		Http::fake([
			'https://api.easy-orders.net/api/v1/external-apps/products/' . $externalProductId => Http::response([
				'id'          => $externalProductId,
				'name'        => 'Test Product',
				'price'       => 1000,
				'sale_price'  => 900,
				'description' => '<p>Desc</p>',
				'slug'        => 'test-product',
				'sku'         => 'TP-1',
				'thumb'       => 'https://example.com/thumb.jpg',
				'images'      => ['https://example.com/img1.jpg'],
				'quantity'    => 0,
				'track_stock' => false,
				'categories'  => [
					[
						'id'    => 'cat-1',
						'name'  => 'Category 1',
						'slug'  => 'category-1',
						'thumb' => 'https://example.com/cat.jpg',
					],
				],
				'variations'  => [],
				'variants'    => [],
			], 200),
		]);

		// Act
		/** @var ProductSyncService $service */
		$service = app(ProductSyncService::class);
		$product = $service->syncOne($externalProductId);

		// Assert product and category were created
		$this->assertInstanceOf(Product::class, $product);
		$this->assertSame($shop->id, $product->shop_id);
		$this->assertNotNull($product->category_id);

		/** @var Category $category */
		$category = Category::query()->find($product->category_id);
		$this->assertNotNull($category);

		// Stock should exist with large quantity (since track_stock = false)
		/** @var Stock|null $stock */
		$stock = $product->stocks()->first();
		$this->assertNotNull($stock);
		$this->assertSame('TP-1', $stock->sku);
		$this->assertSame(900.0, (float) $stock->price);
		$this->assertGreaterThan(0, $stock->quantity);
	}
}


