<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Services;

use App\Models\Category;
use App\Models\ExtraGroup;
use App\Models\ExtraValue;
use App\Models\Language;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockExtra;
use App\Models\Settings;
use App\Services\CategoryServices\CategoryService;
use App\Services\ProductService\ProductService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Modules\EasyOrders\Entities\EasyOrdersProduct;
use Modules\EasyOrders\Entities\EasyOrdersStore;

class ProductSyncService
{
	public function __construct(
		private readonly ProductService $productService,
		private readonly CategoryService $categoryService,
	) {
		$this->rateLimitPerMinute = (int) Config::get('easyorders.rate_limit_per_minute', 40);
	}

	/**
	 * Track outbound EasyOrders API calls to respect public rate limits.
	 */
	private int $rateLimitPerMinute = 40;
	private int $requestsInCurrentWindow = 0;
	private float $windowStartedAt = 0.0;

	/**
	 * Sync all products starting from a given page.
	 */
	public function syncAll(int $page = 1): void
	{
		$store = $this->getActiveStore();
		$currentPage = $page;

		do {
			$list = $this->fetchProductList($store, $currentPage);
			$items = $this->extractProductsFromListResponse($list);

			if (empty($items)) {
				break;
			}

			foreach ($items as $item) {
				$externalId = (string) Arr::get($item, 'id');
				if (!$externalId) {
					continue;
				}
				$this->syncOne($externalId);
			}

			$currentPage++;
		} while (true);
	}

	/**
	 * Sync a single product by its EasyOrders ID and return the internal Product.
	 */
	public function syncOne(string $externalProductId): ?Product
	{
		$store = $this->getActiveStore();
		$payload = $this->fetchProduct($store, $externalProductId);

		if (empty($payload)) {
			return null;
		}

		return DB::transaction(function () use ($store, $payload) {
			$product = $this->upsertProductFromEasyOrders($store, $payload);
			$this->syncVariants($product, $payload);

			return $product->fresh([
				'translation',
				'translations',
				'category.translation',
				'stocks.stockExtras.value',
				'stocks.stockExtras.group.translation',
			]);
		});
	}

	private function getActiveStore(): EasyOrdersStore
	{
		/** @var EasyOrdersStore|null $store */
		$store = EasyOrdersStore::query()
			->where('status', 'active')
			->orderBy('id')
			->first();

		if (!$store) {
			abort(500, 'No active EasyOrders store configured.');
		}

		if (!$store->api_key) {
			abort(500, 'Active EasyOrders store is missing API key.');
		}

		return $store;
	}

	private function fetchProductList(EasyOrdersStore $store, int $page): array
	{
		$this->throttle();

		$baseUrl = rtrim((string) Config::get('easyorders.base_url'), '/');
		$url = $baseUrl . '/external-apps/products?page=' . $page;

		$response = Http::withHeaders([
			'Api-Key' => (string) $store->api_key,
		])
			->acceptJson()
			->timeout(15)
			->get($url);

		if (!$response->successful()) {
			abort(502, 'Failed to fetch products list from EasyOrders.');
		}

		return $response->json() ?? [];
	}

	private function fetchProduct(EasyOrdersStore $store, string $externalProductId): array
	{
		$this->throttle();

		$baseUrl = rtrim((string) Config::get('easyorders.base_url'), '/');
		$url = $baseUrl . '/external-apps/products/' . urlencode($externalProductId);

		$response = Http::withHeaders([
			'Api-Key' => (string) $store->api_key,
		])
			->acceptJson()
			->timeout(15)
			->get($url);

		if (!$response->successful()) {
			abort(502, 'Failed to fetch product details from EasyOrders.');
		}

		return $response->json() ?? [];
	}

	/**
	 * Simple per-minute throttling to keep within EasyOrders public rate limit.
	 */
	private function throttle(): void
	{
		$limit = max(1, $this->rateLimitPerMinute);
		$now = microtime(true);

		// Start or reset window every 60 seconds
		if ($this->windowStartedAt === 0.0 || ($now - $this->windowStartedAt) >= 60.0) {
			$this->windowStartedAt = $now;
			$this->requestsInCurrentWindow = 0;
		}

		// If we've reached the limit, sleep until the current window completes
		if ($this->requestsInCurrentWindow >= $limit) {
			$elapsed = $now - $this->windowStartedAt;
			$remaining = 60.0 - $elapsed;

			if ($remaining > 0) {
				usleep((int) ceil($remaining * 1_000_000));
			}

			// Reset window after sleep
			$this->windowStartedAt = microtime(true);
			$this->requestsInCurrentWindow = 0;
		}

		$this->requestsInCurrentWindow++;
	}

	/**
	 * Download remote EasyOrders product images into local storage and return
	 * an array of relative paths suitable for Product::uploads().
	 *
	 * @param array $urls
	 * @return array
	 */
	private function downloadProductImages(array $urls): array
	{
		$urls = array_values(array_filter($urls));
		if (empty($urls)) {
			return [];
		}

		$stored = [];
		$isAws = Settings::where('key', 'aws')->first()?->value;

		foreach ($urls as $url) {
			try {
				$url = (string) $url;
				if ($url === '' || (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://'))) {
					continue;
				}

				$response = Http::timeout(20)->get($url);
				if (!$response->successful()) {
					continue;
				}

				$content = $response->body();
				if ($content === '' || $content === null) {
					continue;
				}

				$path = parse_url($url, PHP_URL_PATH) ?: '';
				$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg');

				$dir = 'products';
				$date = Str::slug(now()->format('Y-m-d H:i:s')) . Str::random(6);

				// Physical storage path (relative to disk root)
				$diskPath = "images/$dir/$date.$ext";

				Storage::disk($isAws ? 's3' : 'public')->put($diskPath, $content, 'public');

				// Public-facing relative path (used by Loadable::uploads & Gallery.path)
				$stored[] = "storage/$diskPath";
			} catch (\Throwable) {
				continue;
			}
		}

		return $stored;
	}

	/**
	 * EasyOrders list endpoint may return either a plain array of products or a wrapper with `data`.
	 */
	private function extractProductsFromListResponse(array $response): array
	{
		if (isset($response['data']) && is_array($response['data'])) {
			return $response['data'];
		}

		// If the response is already a list of products
		if (array_is_list($response)) {
			return $response;
		}

		return [];
	}

	private function upsertProductFromEasyOrders(EasyOrdersStore $store, array $eoProduct): Product
	{
		$externalId = (string) Arr::get($eoProduct, 'id');
		$externalSku = (string) Arr::get($eoProduct, 'sku') ?: (string) Arr::get($eoProduct, 'slug', '');

		/** @var EasyOrdersProduct|null $mapping */
		$mapping = EasyOrdersProduct::query()
			->where('store_id', $store->id)
			->where('external_product_id', $externalId)
			->first();

		$shopId = (int) Config::get('easyorders.main_shop_id', 1);
		$categoryId = $this->resolveCategoryId($eoProduct);

		$defaultLocale = Language::where('default', 1)->first()?->locale ?? 'en';

		$remoteImages = collect([
			Arr::get($eoProduct, 'thumb'),
		])
			->filter()
			->merge(Arr::get($eoProduct, 'images', []))
			->filter()
			->values()
			->all();

		// Download remote images into local storage and use local paths for galleries.
		$images = $this->downloadProductImages($remoteImages);

		// Normalize and limit keywords to column size (191 chars) without newlines.
		$rawKeywords = (string) Arr::get($eoProduct, 'meta_description', '');
		$normalizedKeywords = preg_replace('/\s+/', ' ', $rawKeywords);
		$normalizedKeywords = Str::limit((string) $normalizedKeywords, 191, '');

		$data = [
			'shop_id'     => $shopId,
			'category_id' => $categoryId,
			'brand_id'    => null,
			'tax'         => 0,
			'digital'     => (bool) Arr::get($eoProduct, 'is_digital', false),
			'keywords'    => $normalizedKeywords,

			'title' => [
				$defaultLocale => (string) Arr::get($eoProduct, 'name', ''),
			],
			'description' => [
				$defaultLocale => (string) Arr::get($eoProduct, 'description', ''),
			],

			'images' => $images,
		];

		// Create or update the product via ProductService
		if ($mapping && $mapping->product_id) {
			/** @var Product|null $existing */
			$existing = Product::query()->find($mapping->product_id);

			if ($existing) {
				$result = $this->productService->update($existing->uuid, $data);
				if (!data_get($result, 'status')) {
					throw new \RuntimeException('Failed to update product from EasyOrders: ' . data_get($result, 'message'));
				}
				/** @var Product $product */
				$product = data_get($result, 'data');
			} else {
				$mapping = null;
				$result = $this->productService->create($data);
				if (!data_get($result, 'status')) {
					throw new \RuntimeException('Failed to create product from EasyOrders: ' . data_get($result, 'message'));
				}
				/** @var Product $product */
				$product = data_get($result, 'data');
			}
		} else {
			$result = $this->productService->create($data);
			if (!data_get($result, 'status')) {
				throw new \RuntimeException('Failed to create product from EasyOrders: ' . data_get($result, 'message'));
			}
			/** @var Product $product */
			$product = data_get($result, 'data');
		}

		EasyOrdersProduct::query()->updateOrCreate(
			[
				'store_id' => $store->id,
				'external_product_id' => $externalId,
			],
			[
				'product_id' => $product->id,
				'external_sku' => $externalSku,
				'payload' => $eoProduct,
				'last_synced_at' => CarbonImmutable::now(),
			]
		);

		return $product;
	}

	private function resolveCategoryId(array $eoProduct): ?int
	{
		$categories = Arr::get($eoProduct, 'categories', []);
		if (!is_array($categories) || empty($categories)) {
			return null;
		}

		$first = $categories[0];

		$slug = (string) Arr::get($first, 'slug', '');
		$name = (string) Arr::get($first, 'name', '');
		$thumb = Arr::get($first, 'thumb');

		// Try by slug
		if ($slug !== '') {
			$existing = Category::query()->where('slug', $slug)->first();
			if ($existing) {
				return $existing->id;
			}
		}

		// Try by translation title
		if ($name !== '') {
			$existingByTitle = Category::query()
				->whereHas('translation', function ($q) use ($name) {
					$q->where('title', $name);
				})
				->first();

			if ($existingByTitle) {
				return $existingByTitle->id;
			}
		}

		// Create new category via CategoryService; type child by default.
		$defaultLocale = Language::where('default', 1)->first()?->locale ?? 'en';

		// Determine parent_id: by default 0 (root). If a root_category_slug is configured,
		// attach new categories under that root when it exists.
		$parentId = 0;
		$rootSlug = Config::get('easyorders.root_category_slug');
		if ($rootSlug) {
			$root = Category::query()->where('slug', $rootSlug)->first();
			if ($root) {
				$parentId = $root->id;
			}
		}

		$data = [
			// CategoryService expects the string key (e.g. 'child'), which it maps to the int type.
			'type'      => Category::TYPES_VALUES[Category::CHILD] ?? 'child',
			'parent_id' => $parentId,
			'title'     => [
				$defaultLocale => $name ?: ('EasyOrders ' . ($slug ?: 'Category')),
			],
			'images'    => $thumb ? [$thumb] : [],
		];

		$result = $this->categoryService->create($data);
		if (!data_get($result, 'status')) {
			throw new \RuntimeException('Failed to create category from EasyOrders.');
		}

		/** @var Category|null $created */
		$created = Category::query()
			->orderByDesc('id')
			->first();

		return $created?->id;
	}

	private function syncVariants(Product $product, array $eoProduct): void
	{
		$shopId = $product->shop_id;

		$variations = Arr::get($eoProduct, 'variations', []);
		$variants = Arr::get($eoProduct, 'variants', []);

		/** @var Collection<string, array{group: ExtraGroup, props: array}> $groupsByName */
		$groupsByName = collect();

		foreach ($variations as $variation) {
			$name = (string) Arr::get($variation, 'name', '');
			if ($name === '') {
				continue;
			}

			$group = $this->ensureExtraGroup($shopId, $variation);
			$groupsByName->put($name, [
				'group' => $group,
				'props' => Arr::get($variation, 'props', []),
			]);
		}

		// Clear existing stocks and recreate them based on EasyOrders payload.
		$product->stocks()->delete();

		// If there are no explicit variants, create a single stock row from the base product data.
		if (empty($variants)) {
			$baseSalePrice = Arr::get($eoProduct, 'sale_price');
			$basePriceRaw  = Arr::get($eoProduct, 'price', 0);

			// If sale_price is set and > 0, use it; if it's 0 (or not set), fall back to price.
			if ($baseSalePrice !== null && (float) $baseSalePrice > 0) {
				$basePrice = (float) $baseSalePrice;
			} else {
				$basePrice = (float) $basePriceRaw;
			}
			$baseQuantity = (int) Arr::get($eoProduct, 'quantity', 0);
			$trackStock = (bool) Arr::get($eoProduct, 'track_stock', false);

			if (!$trackStock && $baseQuantity <= 0) {
				$baseQuantity = 999999;
			}

			if ($basePrice > 0 || $baseQuantity > 0) {
				Stock::query()->create([
					'product_id' => $product->id,
					'sku'        => (string) Arr::get($eoProduct, 'sku', ''),
					'price'      => $basePrice,
					'quantity'   => $baseQuantity,
					'img'        => $product->img ?? '',
				]);
			}

			return;
		}

		foreach ($variants as $variant) {
			$sku = (string) (Arr::get($variant, 'taager_code') ?: Arr::get($eoProduct, 'sku', ''));

			// Price selection with sale_price fallback logic:
			$variantSale = Arr::get($variant, 'sale_price');
			$variantPriceRaw = Arr::get($variant, 'price');
			$eoSale = Arr::get($eoProduct, 'sale_price');
			$eoPriceRaw = Arr::get($eoProduct, 'price', 0);

			if ($variantSale !== null && (float) $variantSale > 0) {
				$price = (float) $variantSale;
			} elseif ($variantPriceRaw !== null) {
				$price = (float) $variantPriceRaw;
			} elseif ($eoSale !== null && (float) $eoSale > 0) {
				$price = (float) $eoSale;
			} else {
				$price = (float) $eoPriceRaw;
			}
			$rawQuantity = (int) Arr::get($variant, 'quantity', 0);
			$trackStock = (bool) Arr::get($eoProduct, 'track_stock', false);

			$variantOutOfStock = false;
			$stockExtrasTemplates = [];

			// Map variation_props to StockExtras (templates; stock_id filled after stock is created)
			foreach (Arr::get($variant, 'variation_props', []) as $vp) {
				$variationName = (string) Arr::get($vp, 'variation', '');
				$propName = (string) Arr::get($vp, 'variation_prop', '');

				if ($variationName === '' || $propName === '') {
					continue;
				}

				$row = $groupsByName->get($variationName);
				if (!$row) {
					continue;
				}

				/** @var ExtraGroup $group */
				$group = $row['group'];
				$eoProps = $row['props'] ?? [];
				$eoPropItem = collect($eoProps)->firstWhere('name', $propName) ?? ['name' => $propName];

				$extraValue = $this->ensureExtraValue($group, $eoPropItem);

				if ((bool) Arr::get($eoPropItem, 'is_out_of_stock', false)) {
					$variantOutOfStock = true;
				}

				$stockExtrasTemplates[] = [
					'extra_group_id' => $group->id,
					'extra_value_id' => $extraValue->id,
				];
			}

			// Decide final quantity for the variant
			if ($variantOutOfStock) {
				$quantity = 0;
			} else {
				$quantity = $rawQuantity;
				if (!$trackStock && $quantity <= 0) {
					$quantity = 999999;
				}
			}

			/** @var Stock $stock */
			$stock = Stock::query()->create([
				'product_id' => $product->id,
				'sku'        => $sku,
				'price'      => $price,
				'quantity'   => $quantity,
				'img'        => $product->img ?? '',
			]);

			if (!empty($stockExtrasTemplates)) {
				foreach ($stockExtrasTemplates as $extra) {
					StockExtra::query()->updateOrCreate(
						[
							'stock_id'       => $stock->id,
							'extra_group_id' => $extra['extra_group_id'],
							'extra_value_id' => $extra['extra_value_id'],
						],
						[
							'stock_id'       => $stock->id,
							'extra_group_id' => $extra['extra_group_id'],
							'extra_value_id' => $extra['extra_value_id'],
						]
					);
				}
			}
		}
	}

	private function ensureExtraGroup(int $shopId, array $variation): ExtraGroup
	{
		$name = (string) Arr::get($variation, 'name', '');
		$type = (string) Arr::get($variation, 'type', 'text');
		$defaultLocale = Language::where('default', 1)->first()?->locale ?? 'en';

		/** @var ExtraGroup|null $existing */
		$existing = ExtraGroup::query()
			->where('shop_id', $shopId)
			->whereHas('translation', function ($q) use ($name) {
				$q->where('title', $name);
			})
			->first();

		if ($existing) {
			return $existing;
		}

		if (!in_array($type, ExtraGroup::TYPES, true)) {
			$type = 'text';
		}

		$group = ExtraGroup::query()->create([
			'shop_id' => $shopId,
			'type'    => $type,
			'active'  => true,
		]);

		$group->translations()->create([
			'locale' => $defaultLocale,
			'title'  => $name ?: 'Option',
		]);

		return $group;
	}

	private function ensureExtraValue(ExtraGroup $group, array $prop): ExtraValue
	{
		$value = (string) Arr::get($prop, 'name', '');

		/** @var ExtraValue|null $existing */
		$existing = ExtraValue::query()
			->where('extra_group_id', $group->id)
			->where('value', $value)
			->first();

		if ($existing) {
			return $existing;
		}

		$extraValue = ExtraValue::query()->create([
			'extra_group_id' => $group->id,
			'value'          => $value,
			'active'         => true,
		]);

		return $extraValue;
	}
}


