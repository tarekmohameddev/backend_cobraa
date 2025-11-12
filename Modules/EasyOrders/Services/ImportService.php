<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Services;

use Illuminate\Support\Facades\DB;
use Modules\EasyOrders\Entities\EasyOrdersTempOrder;
use App\Services\OrderService\OrderService;
use App\Models\Stock;
use App\Models\User;
use App\Models\Order as OrderModel;

class ImportService
{
	public function import(int $tempOrderId): void
	{
		$temp = EasyOrdersTempOrder::query()->find($tempOrderId);
		if (!$temp) {
			return;
		}
		if (in_array($temp->status, ['imported'])) {
			return;
		}
		// Only import validated or approved
		if (!in_array($temp->status, ['validated', 'approved'])) {
			return;
		}

		DB::transaction(function () use ($temp) {
			$normalized = $temp->normalized ?? [];
			$items = (array) ($normalized['items'] ?? []);

			// Ensure customer exists
			$customerName = $temp->customer_name ?: data_get($normalized, 'customer.full_name');
			$customerPhone = $temp->customer_phone ?: data_get($normalized, 'customer.phone');
			/** @var User|null $user */
			$user = null;
			if ($customerPhone) {
				$user = User::query()->firstOrCreate(
					['phone' => (string) $customerPhone],
					['firstname' => (string) ($customerName ?: 'Guest'), 'active' => true]
				);
			}

			// Prepare address
			$addressText = $temp->address ?: data_get($normalized, 'customer.address');
			$government = $temp->government ?: data_get($normalized, 'customer.government');
			$addressArray = [
				'government' => $government,
				'line' => $addressText,
			];

			// We do not create a persistent UserAddress per your preference.

			// Build POS payload grouped by shop
			$byShop = [];
			foreach ($items as $item) {
				$stockId = data_get($item, 'resolved.stock_id');
				$qty = (int) data_get($item, 'quantity', 0);
				if (!$stockId || $qty <= 0) {
					continue;
				}
				/** @var Stock|null $stock */
				$stock = Stock::with('product:id,shop_id')->find($stockId);
				if (!$stock || !$stock->product?->shop_id) {
					continue;
				}
				$shopId = $stock->product->shop_id;
				$byShop[$shopId]['shop_id'] = $shopId;
				$byShop[$shopId]['products'][] = [
					'stock_id' => $stockId,
					'quantity' => $qty,
					'bonus' => false,
				];
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
				'username' => (string) ($customerName ?: ''),
				'address' => $addressArray,
				'location' => [],
				'delivery_type' => OrderModel::DELIVERY,
			];

			$result = (new OrderService)->create($payload);

			if (data_get($result, 'status') === true) {
				$orders = data_get($result, 'data', []);
				// Apply external shipping_cost as delivery fee on created orders
				$shipping = (float) ($temp->shipping_cost ?? data_get($normalized, 'totals.shipping_cost', 0));
				if ($shipping > 0 && is_array($orders) && count($orders) > 0) {
					$orderCount = count($orders);
					if ($orderCount === 1) {
						$order = $orders[0];
						if (is_object($order) && method_exists($order, 'update')) {
							$order->update([
								'delivery_fee' => $shipping,
								'total_price'  => ($order->total_price ?? 0) + $shipping,
							]);
						} elseif (is_array($order) && isset($order['id'])) {
							$found = \App\Models\Order::find((int) $order['id']);
							if ($found) {
								$found->update([
									'delivery_fee' => $shipping,
									'total_price'  => ($found->total_price ?? 0) + $shipping,
								]);
							}
						}
					} else {
						$portion = round($shipping / $orderCount, 2);
						$applied = 0.0;
						foreach ($orders as $idx => $order) {
							$fee = ($idx === $orderCount - 1) ? round($shipping - $applied, 2) : $portion;
							$applied += $fee;
							if (is_object($order) && method_exists($order, 'update')) {
								$order->update([
									'delivery_fee' => $fee,
									'total_price'  => ($order->total_price ?? 0) + $fee,
								]);
							} elseif (is_array($order) && isset($order['id'])) {
								$found = \App\Models\Order::find((int) $order['id']);
								if ($found) {
									$found->update([
										'delivery_fee' => $fee,
										'total_price'  => ($found->total_price ?? 0) + $fee,
									]);
								}
							}
						}
					}
				}
				$firstOrderId = is_array($orders) && isset($orders[0]) ? (int) data_get($orders[0], 'id') : null;
				$temp->status = 'imported';
				$temp->imported_order_id = $firstOrderId;
				$temp->save();
			} else {
				$temp->status = 'import_failed';
				$temp->failure_reason = (string) data_get($result, 'message', 'import failed');
				$temp->save();
			}
		});
	}
}


