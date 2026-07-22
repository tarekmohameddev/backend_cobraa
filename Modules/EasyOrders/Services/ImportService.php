<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Services;

use Illuminate\Support\Facades\DB;
use Modules\EasyOrders\Entities\EasyOrdersTempOrder;
use Carbon\Carbon;
use App\Models\Region;
use App\Models\City;
use App\Models\Area;
use App\Models\UserAddress;
use App\Models\Transaction;
use App\Services\OrderService\OrderService;
use App\Models\Stock;
use App\Models\User;
use App\Models\Order as OrderModel;
use App\Services\TransactionService\TransactionService;
use App\Models\DeliveryPrice;
use Modules\OrderEnhancements\Services\OrderUpdateService;
use Illuminate\Support\Arr;

class ImportService
{
	public function __construct(private readonly StockResolver $stockResolver)
	{
	}

	public function import(int $tempOrderId): void
	{
		$temp = EasyOrdersTempOrder::query()->find($tempOrderId);
		if (!$temp) {
			return;
		}
		// Skip only when import actually produced an order.
		if ($temp->status === 'imported' && $temp->imported_order_id) {
			return;
		}
		// Only import validated or approved
		if (!in_array($temp->status, ['validated', 'approved', 'imported', 'import_failed'], true)) {
			return;
		}

		DB::transaction(function () use ($temp) {
			$normalized      = $temp->normalized ?? [];
			$items           = (array) ($normalized['items'] ?? []);
			$paymentMethod   = (string) ($temp->payment_method ?? data_get($normalized, 'payment_method', ''));
			$externalStatus  = (string) data_get($temp->payload ?? [], 'status', data_get($normalized, 'status', ''));
			
			// Check if this order had a payment timeout
			$paymentTimeout = (bool) Arr::get($normalized, 'metadata.payment_timeout', false);
			$timeoutMinutes = (int) Arr::get($normalized, 'metadata.payment_timeout_minutes', 30);

			// Ensure customer exists
			$customerName = $temp->customer_name ?: data_get($normalized, 'customer.full_name');
			$customerPhone = $temp->customer_phone ?: data_get($normalized, 'customer.phone');
			$customerPhoneAlt = $temp->customer_phone_alt ?: data_get($normalized, 'customer.phone_alt');
			/** @var User|null $user */
			$user = null;
			if ($customerPhone) {
				$user = User::query()->firstOrCreate(
					['phone' => (string) $customerPhone],
					['firstname' => (string) ($customerName ?: 'Guest'), 'active' => true]
				);
			}

			// Prepare address - simple free text, ignore government
			$addressText = (string) ($temp->address ?: data_get($normalized, 'customer.address', ''));

			// Resolve default region/country once, but keep city/area null by default.
			// This forces admins to explicitly select the correct city/area later.
			$defaultCity = City::query()->active()->orderBy('id')->first();
			$defaultArea = null;

			if ($defaultCity) {
				$defaultArea = Area::query()
					->active()
					->where('city_id', $defaultCity->id)
					->orderBy('id')
					->first();
			}

			$regionId  = $defaultCity?->region_id;
			$countryId = $defaultCity?->country_id;
			$cityId    = null;
			$areaId    = null;

			// Fallback to first active region/area if city is missing
			if (!$regionId) {
				$defaultRegion = Region::query()->active()->orderBy('id')->first();
				$regionId = $defaultRegion?->id;
			}

			// We intentionally do NOT auto-fill city/area: they should remain null by default
			// so that admins are forced to choose a concrete city/area before fulfillment.

			// Order address JSON: flat structure with free-text address and location IDs
			$orderAddress = [
				'address'    => $addressText,
				'region_id'  => $regionId,
				'country_id' => $countryId,
				'city_id'    => $cityId,
				'area_id'    => $areaId,
			];

			// Create a persistent UserAddress for this imported order if we have a user
			$addressId = null;
			if ($user) {
				$userAddressData = [
					'user_id'    => $user->id,
					// Store address as plain string to match other address flows
					'address'    => $addressText,
					'location'   => [],
					'active'     => true,
					'firstname'  => (string) ($customerName ?: $user->firstname ?: ''),
					'lastname'   => '',
					'phone'      => (string) ($customerPhone ?: $user->phone ?: ''),
					'title'      => 'EasyOrders Address',
					'region_id'  => $regionId,
					'country_id' => $countryId,
					'city_id'    => $cityId,
					'area_id'    => $areaId,
				];

				/** @var UserAddress $userAddress */
				$userAddress = UserAddress::query()->create($userAddressData);
				$addressId   = $userAddress->id;
			}

			// Always use "today + 4 days" for delivery date (ignore EasyOrders delivery dates)
			$deliveryDateString = Carbon::now()->addDays(4)->format('Y-m-d H:i:s');

			// Build POS payload grouped by shop
			$byShop = [];
			foreach ($items as $item) {
				$qty = (int) data_get($item, 'quantity', 0);
				if ($qty <= 0) {
					continue;
				}

				/** @var Stock|null $stock */
				$stock = $this->stockResolver->resolveForItem($item);
				if (!$stock || !$stock->product?->shop_id) {
					continue;
				}

				$stockId = $stock->id;
				$shopId = $stock->product->shop_id;
				$byShop[$shopId]['shop_id'] = $shopId;
				$byShop[$shopId]['products'][] = [
					'stock_id' => $stockId,
					'quantity' => $qty,
					'bonus' => false,
				];
			}

			if (empty($byShop)) {
				$temp->status = 'import_failed';
				$temp->failure_reason = 'No importable items: product stocks may have been replaced or are unavailable';
				$temp->save();

				return;
			}

			$payload = [
				'data' => array_values($byShop),
				'notes' => [
					'source' => 'easyorders',
					'external_order_id' => $temp->external_order_id,
					'short_id' => $temp->short_id,
				],
			// Customer and delivery details mapped into Order fields
			'user_id' => $user?->id,
			'phone' => (string) ($customerPhone ?: ''),
			'phone_alt' => (string) ($customerPhoneAlt ?: ''),
			'username' => (string) ($customerName ?: ''),
				// Store address as flat JSON: {"address": "...", "country_id": ..., "city_id": ..., "area_id": ...}
				'address' => $orderAddress,
				'location' => [],
				'address_id' => $addressId,
				'delivery_type' => OrderModel::DELIVERY,
				// Optional delivery date/time coming from EasyOrders (if present)
				// Stored with seconds precision when parsable (e.g. 2025-11-30 18:06:00)
				'delivery_date' => $deliveryDateString,
			];

			$result = (new OrderService)->create($payload);

			if (data_get($result, 'status') === true) {
				$orders = data_get($result, 'data', []);
				// Normalize to actual Order models
				$orderModels = [];
				foreach ($orders as $order) {
					if (is_object($order) && $order instanceof OrderModel) {
						$orderModels[] = $order->fresh();
					} elseif (is_array($order) && isset($order['id'])) {
						$found = OrderModel::find((int) $order['id']);
						if ($found) {
							$orderModels[] = $found;
						}
					}
				}

				// Apply external shipping_cost as delivery fee on created orders
				$shipping = (float) ($temp->shipping_cost ?? data_get($normalized, 'totals.shipping_cost', 0));
				if ($shipping > 0 && !empty($orderModels)) {
					$orderCount = count($orderModels);
					if ($orderCount === 1) {
						$order = $orderModels[0];
						$order->update([
							'delivery_fee' => $shipping,
							'total_price'  => ($order->total_price ?? 0) + $shipping,
						]);
						$orderModels[0] = $order->fresh();
					} else {
						$portion = round($shipping / $orderCount, 2);
						$applied = 0.0;
						foreach ($orderModels as $idx => $order) {
							$fee = ($idx === $orderCount - 1) ? round($shipping - $applied, 2) : $portion;
							$applied += $fee;
							$order->update([
								'delivery_fee' => $fee,
								'total_price'  => ($order->total_price ?? 0) + $fee,
							]);
							$orderModels[$idx] = $order->fresh();
						}
					}
				}

				// Apply external order-level coupon discount, distributed across created orders
				$couponDiscount = (float) data_get($normalized, 'totals.coupon_discount', 0);
				if ($couponDiscount > 0 && !empty($orderModels)) {
					$sumTotal = collect($orderModels)->sum(fn (OrderModel $o) => (float) $o->total_price);

					if ($sumTotal > 0) {
						$remaining = min($couponDiscount, $sumTotal);
						$applied   = 0.0;

						foreach ($orderModels as $idx => $order) {
							$isLast = $idx === (count($orderModels) - 1);

							if ($isLast) {
								$portion = round($remaining - $applied, 2);
							} else {
								$ratio   = $order->total_price / $sumTotal;
								$portion = round($remaining * $ratio, 2);
								$applied += $portion;
							}

							if ($portion <= 0) {
								continue;
							}

							$newTotalPrice   = max(0, ($order->total_price ?? 0) - $portion);
							$newTotalDiscount = max(0, ($order->total_discount ?? 0) + $portion);

							$order->update([
								'total_price'    => $newTotalPrice,
								'total_discount' => $newTotalDiscount,
							]);

							$orderModels[$idx] = $order->fresh();
						}
					}
				}

				// Apply price discount: when EasyOrders external prices total less than the
				// sum of internal catalog prices, apply the difference as an order discount.
				// This handles both combo splits and regular items with price mismatches.
				if (!empty($orderModels)) {
					$this->calculateExternalPriceDiscount($items, $orderModels);
				}

				// Attach a default delivery_price_id for each imported order when possible.
				// We reuse the same default city/area that were used for address creation,
				// so EasyOrders imports always have a valid delivery pricing configuration.
				if (!empty($orderModels)) {
					foreach ($orderModels as $idx => $order) {
						/** @var OrderModel $order */
						if (!$order->shop_id) {
							continue;
						}

						$deliveryPrice = $this->resolveDeliveryPriceForShopAndLocation((int) $order->shop_id, $cityId, $areaId);

						if ($deliveryPrice) {
							$order->update([
								'delivery_price_id' => $deliveryPrice->id,
							]);

							$orderModels[$idx] = $order->fresh();
						}
					}
				}

				// Determine transaction status based on EasyOrders payment method & status
				$txStatus = Transaction::STATUS_PROGRESS;
				$paymentMethodLower = strtolower($paymentMethod);

				if ($paymentMethodLower === 'cod' || $paymentMethodLower === 'cash_on_delivery') {
					$txStatus = Transaction::STATUS_PROGRESS;
				} else {
					if ($externalStatus === 'paid') {
						$txStatus = Transaction::STATUS_PAID;
					} elseif ($externalStatus === 'paid_failed') {
						$txStatus = Transaction::STATUS_PROGRESS;
					} elseif ($paymentTimeout) {
						// Payment timeout: treat as unpaid
						$txStatus = Transaction::STATUS_PROGRESS;
					}
				}

				$addFailedPaymentNote = $externalStatus === 'paid_failed' && $paymentMethodLower !== 'cod' && $paymentMethodLower !== 'cash_on_delivery';

				// Create transactions for each imported order
				foreach ($orderModels as $orderModel) {
					/** @var OrderModel $orderModel */
					$transaction = $orderModel->createTransaction([
						'price'              => $orderModel->total_price,
						'user_id'            => $orderModel->user_id,
						// We intentionally keep payment_sys_id null here; EasyOrders is an external source
						'payment_sys_id'     => 1,
						'payment_trx_id'     => (string) $temp->external_order_id,
						'note'               => "EasyOrders order #{$temp->external_order_id}",
						'perform_time'       => now(),
						'status'             => $txStatus,
						'status_description' => "EasyOrders order #{$temp->external_order_id}",
					]);

					// If fully paid online, mirror normal flow & unlock digital files
					if ($txStatus === Transaction::STATUS_PAID) {
						(new TransactionService)->digitalFile($orderModel);
					}

					// For failed online payments, keep status in progress and attach a human-readable note
					if ($addFailedPaymentNote) {
						$failedNote = 'Customer attempted an online payment via EasyOrders, but it failed.';
						$currentNote = (string) $orderModel->note;
						$orderModel->update([
							'note' => trim($currentNote . (empty($currentNote) ? '' : ' ') . $failedNote),
						]);
					}
					
					// For payment timeout, add an internal order update note
					if ($paymentTimeout) {
						// Find an admin user or use the order's user for the note
						$noteUser = User::whereHas('roles', fn($q) => $q->where('name', 'admin'))->first();
						if (!$noteUser && $orderModel->user_id) {
							$noteUser = User::find($orderModel->user_id);
						}
						
						if ($noteUser) {
							$timeoutNote = "Customer attempted to pay online via EasyOrders. The system waited {$timeoutMinutes} minutes for payment completion, but the payment was not completed. Therefore, the order was imported as unpaid.";
							
							try {
								(new OrderUpdateService)->create($orderModel, $noteUser, [
									'update_type' => 'note',
									'content' => $timeoutNote,
									'is_internal' => true,
									'metadata' => [
										'source' => 'easyorders',
										'payment_timeout' => true,
										'timeout_minutes' => $timeoutMinutes,
									],
								]);
							} catch (\Throwable $e) {
								// Log error but don't fail the import
								\Log::warning('EasyOrders: Failed to create order update note for payment timeout', [
									'order_id' => $orderModel->id,
									'temp_order_id' => $temp->id,
									'error' => $e->getMessage(),
								]);
							}
						}
					}
				}

				$firstOrderId = !empty($orderModels) ? (int) $orderModels[0]->id : null;

				if (!$firstOrderId) {
					$temp->status = 'import_failed';
					$temp->failure_reason = (string) data_get($result, 'message', 'No orders were created during import');
					$temp->save();

					return;
				}

				$temp->status = 'imported';
				$temp->imported_order_id = $firstOrderId;
				$temp->failure_reason = null;
				$temp->save();
			} else {
				$temp->status = 'import_failed';
				$temp->failure_reason = (string) data_get($result, 'message', 'import failed');
				$temp->save();
			}
		});
	}

	/**
	 * Reconcile catalog prices against EasyOrders external prices.
	 *
	 * After order creation the internal catalog prices are already committed.  When the
	 * sum of those catalog prices exceeds the total the customer paid on EasyOrders we
	 * apply the difference as an order-level discount (identical mechanism to coupon
	 * discount application above), so the order total matches the EasyOrders amount.
	 *
	 * This is intentionally general: it applies to regular items *and* to split
	 * composite SKUs — any item that carried an external price on the EasyOrders side.
	 *
	 * @param array        $items       Normalized items from the temp-order snapshot.
	 * @param OrderModel[] $orderModels Created order models (already fresh() after prior patches).
	 */
	private function calculateExternalPriceDiscount(array $items, array &$orderModels): void
	{
		$externalTotal = $this->sumExternalPrices($items);

		if ($externalTotal === null || $externalTotal <= 0) {
			return;
		}

		// Sum the catalog-price totals from the actual order-detail rows.
		$importedTotal = 0.0;
		foreach ($orderModels as $order) {
			$order->loadMissing('orderDetails');
			$importedTotal += (float) $order->orderDetails->sum('total_price');
		}

		$priceDiscount = round($importedTotal - $externalTotal, 2);

		if ($priceDiscount <= 0) {
			return;
		}

		$this->applyPriceDiscount($orderModels, $priceDiscount);
	}

	/**
	 * Sum the EasyOrders external line totals from normalized items.
	 *
	 * For regular items: adds `resolved.price_policy.external_line_total`.
	 * For combo split items: adds `resolved.price_policy.combo_external_total` once per
	 *   combo group (to avoid counting the same original line total multiple times).
	 *
	 * Returns null when no external price data is present at all (so the caller can
	 * skip discount application rather than applying a zero discount).
	 */
	private function sumExternalPrices(array $items): ?float
	{
		$total                = 0.0;
		$processedComboGroups = [];
		$hasAnyExternalPrice  = false;

		foreach ($items as $item) {
			$comboGroupId = data_get($item, 'resolved.price_policy.combo_group_id');

			if ($comboGroupId !== null) {
				// Combo split part — count the original combo total exactly once.
				if (!in_array($comboGroupId, $processedComboGroups, true)) {
					$comboTotal = data_get($item, 'resolved.price_policy.combo_external_total');
					if ($comboTotal !== null) {
						$total               += (float) $comboTotal;
						$hasAnyExternalPrice  = true;
					}
					$processedComboGroups[] = $comboGroupId;
				}
			} else {
				// Regular (non-split) item.
				$lineTotal = data_get($item, 'resolved.price_policy.external_line_total');
				if ($lineTotal !== null) {
					$total               += (float) $lineTotal;
					$hasAnyExternalPrice  = true;
				}
			}
		}

		return $hasAnyExternalPrice ? $total : null;
	}

	/**
	 * Distribute a price discount across one or more orders proportionally by total_price.
	 *
	 * Uses the same last-item remainder logic as the coupon discount block above to
	 * avoid floating-point drift across multiple orders.
	 *
	 * @param OrderModel[] $orderModels Passed by reference so callers get refreshed models.
	 */
	private function applyPriceDiscount(array &$orderModels, float $discount): void
	{
		$sumTotal = collect($orderModels)->sum(fn (OrderModel $o) => (float) $o->total_price);

		if ($sumTotal <= 0) {
			return;
		}

		$remaining = min($discount, $sumTotal);
		$applied   = 0.0;

		foreach ($orderModels as $idx => $order) {
			$isLast = $idx === (count($orderModels) - 1);

			if ($isLast) {
				$portion = round($remaining - $applied, 2);
			} else {
				$ratio   = $order->total_price / $sumTotal;
				$portion = round($remaining * $ratio, 2);
				$applied += $portion;
			}

			if ($portion <= 0) {
				continue;
			}

			$order->update([
				'total_price'    => max(0, (float) $order->total_price - $portion),
				'total_discount' => (float) ($order->total_discount ?? 0) + $portion,
			]);

			$orderModels[$idx] = $order->fresh();
		}
	}

	/**
	 * Resolve a suitable DeliveryPrice for a given shop and location for EasyOrders imports.
	 *
	 * Strategy:
	 *  - Prefer exact match on city + area.
	 *  - Fallback to:
	 *      - same area, or
	 *      - same city with null area (generic for city).
	 */
	private function resolveDeliveryPriceForShopAndLocation(int $shopId, ?int $cityId, ?int $areaId): ?DeliveryPrice
	{
		$baseQuery = DeliveryPrice::query()->where('shop_id', $shopId);

		// 1. Exact city + area match
		if ($cityId && $areaId) {
			$exact = (clone $baseQuery)
				->where('city_id', $cityId)
				->where('area_id', $areaId)
				->first();

			if ($exact) {
				return $exact;
			}
		}

		// 2. Fallbacks: same area or same city with null area
		if ($cityId || $areaId) {
			$fallback = $baseQuery
				->where(function ($q) use ($cityId, $areaId) {
					if ($areaId) {
						$q->where('area_id', $areaId);
					}

					if ($cityId) {
						$q->orWhere(function ($q2) use ($cityId) {
							$q2->where('city_id', $cityId)->whereNull('area_id');
						});
					}
				})
				->orderBy('area_id', 'desc')
				->first();

			if ($fallback) {
				return $fallback;
			}
		}

		return null;
	}
}


