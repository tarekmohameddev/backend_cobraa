<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Modules\EasyOrders\Entities\EasyOrdersTempOrder;
use Modules\EasyOrders\Jobs\ImportTempOrderJob;
use App\Models\Stock;
use App\Models\Product;

class ValidationService
{
	public function __construct(private readonly StockResolver $stockResolver)
	{
	}
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
			$match = $this->stockResolver->resolveMatchForItem($item);
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

				// When external_price is null, we skip mismatch checks and rely purely on internal pricing.
				$externalPrice = $resolved['price_policy']['external_price'] ?? null;
				if ($externalPrice !== null) {
					$resolved['price_policy']['mismatch'] = round((float) $externalPrice, 2) !== round($internalPrice, 2);
				} else {
					$resolved['price_policy']['mismatch'] = false;
				}
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

		$shouldAutoImport = false;

		DB::transaction(function () use ($temp, $normalized, $errors, &$shouldAutoImport) {
			$temp->normalized = $normalized;
			if (empty($errors)) {
				$temp->status = 'validated';
				$temp->failure_reason = null;

				// Optionally auto-import successfully validated orders
				if (Config::get('easyorders.auto_import_validated', false)) {
					$shouldAutoImport = true;
				}
			} else {
				$temp->status = 'failed';
				$temp->failure_reason = implode('; ', $errors);
			}
			$temp->save();
		});

		// Dispatch import job outside the DB transaction to avoid race conditions
		if ($shouldAutoImport) {
			ImportTempOrderJob::dispatch($temp->id)->onQueue('default');
		}
	}
}

