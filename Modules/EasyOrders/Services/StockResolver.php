<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Services;

use App\Models\Product;
use App\Models\Stock;
use Illuminate\Support\Arr;

class StockResolver
{
	/**
	 * Resolve the best internal stock for an EasyOrders line item.
	 *
	 * Product sync recreates stocks (soft-deleting old rows). Validation may store
	 * a stale stock_id, so always prefer the latest active stock by SKU first.
	 */
	public function resolveForItem(array $item): ?Stock
	{
		$variantSku = Arr::get($item, 'variant.variant_sku');
		$productSku = Arr::get($item, 'product.sku');

		$stock = $this->resolveBySku($variantSku, $productSku);
		if ($stock) {
			return $stock;
		}

		$storedStockId = data_get($item, 'resolved.stock_id');
		if (!$storedStockId) {
			return null;
		}

		/** @var Stock|null $stock */
		$stock = Stock::withTrashed()
			->with('product:id,shop_id,active,status')
			->find($storedStockId);

		if (!$stock?->product?->active || $stock->product->status !== Product::PUBLISHED) {
			return null;
		}

		return $stock;
	}

	/**
	 * @return array{product_id:int,variant_id:int,stock_id:int,shop_id:int|null,stock_model:Stock}|null
	 */
	public function resolveMatchForItem(array $item): ?array
	{
		$stock = $this->resolveForItem($item);

		if (!$stock) {
			return null;
		}

		return [
			'product_id'   => $stock->product_id,
			'variant_id'   => $stock->id,
			'stock_id'     => $stock->id,
			'shop_id'      => $stock->product?->shop_id,
			'stock_model'  => $stock,
		];
	}

	public function resolveBySku(?string $variantSku, ?string $productSku): ?Stock
	{
		foreach (array_filter([$variantSku, $productSku]) as $sku) {
			/** @var Stock|null $stock */
			$stock = Stock::query()
				->with('product:id,shop_id,active,status')
				->where('sku', $sku)
				->whereHas('product', static function ($query) {
					$query->where('active', true)->where('status', Product::PUBLISHED);
				})
				->orderByDesc('id')
				->first();

			if ($stock) {
				return $stock;
			}
		}

		return null;
	}
}
