<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\EasyOrders\Entities\EasyOrdersTempOrder;
use App\Models\Stock;
use App\Models\Product;

class ValidationService
{
	public function validate(int $tempOrderId): void
	{
		$temp = EasyOrdersTempOrder::query()->find($tempOrderId);
		if (!$temp) {
			return;
		}

		$normalized = $temp->normalized ?? [];
		$items = (array) ($normalized['items'] ?? []);
		$errors = [];

		foreach ($items as $index => $item) {
			$variantSku = Arr::get($item, 'variant.variant_sku');
			$productSku = Arr::get($item, 'product.sku');
			$requestedQty = (int) Arr::get($item, 'quantity', 0);

			$resolved = [
				'internal_product_id' => null,
				'internal_variant_id' => null,
				'stock_id' => null,
				'shop_id' => null,
				'price_policy' => Arr::get($item, 'resolved.price_policy', [
					'external_price' => Arr::get($item, 'price'),
					'internal_price' => null,
					'mismatch' => false,
				]),
			];

			// NOTE: Hook up your real SKU resolvers here.
			// Prefer variant SKU, fallback to product SKU.
			$match = $this->resolveBySku($variantSku, $productSku);
			if (!$match) {
				$errors[] = "Unknown SKU at item #".($index + 1).": ".($variantSku ?: $productSku ?: 'N/A');
			} else {
				$resolved['internal_product_id'] = $match['product_id'];
				$resolved['internal_variant_id'] = $match['variant_id'];
				$resolved['stock_id'] = $match['stock_id'];
				$resolved['shop_id'] = $match['shop_id'];

				// Check stock/product availability
				/** @var Stock $stock */
				$stock = $match['stock_model'];
				if (!$stock?->product?->active || $stock?->product?->status !== Product::PUBLISHED) {
					$errors[] = "Inactive product for SKU ".($variantSku ?: $productSku);
				}
				if ($requestedQty <= 0 || $stock->quantity < $requestedQty) {
					$errors[] = "Insufficient stock for SKU ".($variantSku ?: $productSku);
				}

				// Price policy check (simple)
				$internalPrice = (float) $stock->total_price;
				$resolved['price_policy']['internal_price'] = $internalPrice;
				$resolved['price_policy']['mismatch'] = round((float)$resolved['price_policy']['external_price'], 2) !== round($internalPrice, 2);
			}

			// Attach resolution to normalized
			$items[$index]['resolved'] = $resolved;
		}

		$normalized['items'] = $items;

		// Order-level totals coherence
		$cost = (float) ($normalized['totals']['cost'] ?? 0);
		$shipping = (float) ($normalized['totals']['shipping_cost'] ?? 0);
		$total = (float) ($normalized['totals']['total_cost'] ?? 0);
		$couponDiscount = (float) ($normalized['totals']['coupon_discount'] ?? 0);
		if (round($cost + $shipping - $couponDiscount, 2) !== round($total, 2)) {
			$errors[] = "Totals mismatch: cost + shipping - coupon_discount != total";
		}

		DB::transaction(function () use ($temp, $normalized, $errors) {
			$temp->normalized = $normalized;
			if (empty($errors)) {
				$temp->status = 'validated';
				$temp->failure_reason = null;
			} else {
				$temp->status = 'failed';
				$temp->failure_reason = implode('; ', $errors);
			}
			$temp->save();
		});
	}

	/**
	 * Replace with actual SKU resolution against your catalog.
	 * Return ['product_id' => int, 'variant_id' => int|null] or null if not found.
	 */
	private function resolveBySku(?string $variantSku, ?string $productSku): ?array
	{
		$stock = null;
		if ($variantSku) {
			$stock = Stock::with('product:id,shop_id,active,status')->where('sku', $variantSku)->first();
		}
		if (!$stock && $productSku) {
			$stock = Stock::with('product:id,shop_id,active,status')->where('sku', $productSku)->first();
		}
		if (!$stock) {
			return null;
		}
		return [
			'product_id' => $stock->product_id,
			'variant_id' => $stock->id,
			'stock_id' => $stock->id,
			'shop_id' => $stock->product?->shop_id,
			'stock_model' => $stock,
		];
	}
}


