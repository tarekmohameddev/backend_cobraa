<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Modules\EasyOrders\Entities\EasyOrdersStore;
use Modules\EasyOrders\Entities\EasyOrdersTempOrder;
use Modules\EasyOrders\Entities\EasyOrdersWebhookLog;
use Modules\EasyOrders\Repositories\EasyOrdersStoreRepository;
use Modules\EasyOrders\Repositories\EasyOrdersTempOrderRepository;
use Modules\EasyOrders\Jobs\ValidateTempOrderJob;
use Modules\EasyOrders\Jobs\WaitForPaymentStatusJob;

class WebhookService
{
	public function __construct(
		private readonly EasyOrdersStoreRepository $storeRepository,
		private readonly EasyOrdersTempOrderRepository $tempOrderRepository,
	) {}

	public function verifySecret(?string $provided): bool
	{
		return !empty($provided) && is_string($provided);
	}

	public function isIpAllowed(?string $ip): bool
	{
		$allowlist = Config::get('easyorders.ip_allowlist');
		if (empty($allowlist)) {
			return true;
		}
		$list = array_filter(array_map('trim', explode(',', (string) $allowlist)));
		return in_array((string) $ip, $list, true);
	}

	/**
	 * Handle inbound webhook payload:
	 * - verify secret (controller resolves store)
	 * - dedupe by (store_id, external_order_id)
	 * - persist payload + denormalized fields
	 * - enqueue validation
	 */
	public function handle(array $payload, string $webhookSecret, array $headers = []): EasyOrdersTempOrder
	{
		$store = $this->storeRepository->findByWebhookSecret($webhookSecret);
		if (!$store) {
			abort(401, 'Invalid webhook secret');
		}

		// Keep a copy of the raw webhook body for logging/audit purposes.
		$webhookBody = $payload;

		$externalOrderId = (string) Arr::get($payload, 'id');
		if (!$externalOrderId) {
			abort(422, 'Missing order id');
		}

		$duplicate = $this->tempOrderRepository->findDuplicate($store->id, $externalOrderId);
		if ($duplicate) {
			// Idempotent: return existing and ensure validation job is queued (optional)
			return $duplicate;
		}

		// Create a webhook log entry for observability.
		$log = EasyOrdersWebhookLog::query()->create([
			'store_id' => $store->id,
			'request_headers' => $headers,
			'request_body' => $webhookBody,
			'http_status' => null,
			'error' => null,
		]);

		// Fetch full order details from EasyOrders API instead of relying solely on webhook payload.
		$payload = $this->fetchOrderDetails($store, $externalOrderId);

		$normalizedData = $this->normalizeOrderPayload($payload, $externalOrderId);

		$paymentMethod = Arr::get($payload, 'payment_method');
		$externalStatus = Arr::get($payload, 'status');

		$codMethods = ['cod', 'cash_on_delivery'];
		$waitForOnlinePayment = (bool) Config::get('easyorders.wait_for_online_payment', true);

		$shouldWaitForPayment = $waitForOnlinePayment
			&& $paymentMethod !== null
			&& !in_array($paymentMethod, $codMethods, true)
			&& $externalStatus === 'pending_payment';

		Log::info('EasyOrders webhook received order', [
			'store_id' => $store->id,
			'external_order_id' => $externalOrderId,
			'payment_method' => $paymentMethod,
			'external_status' => $externalStatus,
			'should_wait_for_payment' => $shouldWaitForPayment,
		]);

		$temp = DB::transaction(function () use ($store, $payload, $normalizedData, $shouldWaitForPayment) {
			$createdDay = $normalizedData['created_day'];
			$cost = $normalizedData['cost'];
			$shippingCost = $normalizedData['shipping_cost'];
			$totalCost = $normalizedData['total_cost'];
			$expense = $normalizedData['expense'];
			$normalized = $normalizedData['normalized'];

			$temp = new EasyOrdersTempOrder();
			$temp->store_id = $store->id;
			$temp->external_order_id = (string) Arr::get($payload, 'id');
			$temp->short_id = Arr::get($payload, 'short_id');
			$temp->guest_id = Arr::get($payload, 'guest_id');

			if ($shouldWaitForPayment) {
				$temp->status = 'waiting_payment';
			} else {
				$temp->status = 'pending';
			}

			$temp->cost = $cost !== null ? (float) $cost : null;
			$temp->shipping_cost = $shippingCost !== null ? (float) $shippingCost : null;
			$temp->total_cost = $totalCost !== null ? (float) $totalCost : null;
			$temp->expense = $expense !== null ? (float) $expense : null;
			$temp->customer_name = Arr::get($payload, 'full_name');
			$temp->customer_phone = Arr::get($payload, 'phone');
			$temp->government = Arr::get($payload, 'government');
			$temp->address = Arr::get($payload, 'address');
			$temp->payment_method = Arr::get($payload, 'payment_method');
			$temp->ip = Arr::get($payload, 'ip');
			$temp->ip_country = Arr::get($payload, 'ip_country');
			$temp->created_day = $createdDay;
			$temp->payload = $payload;
			$temp->normalized = $normalized;

			if ($shouldWaitForPayment) {
				$timeoutMinutes = (int) Config::get('easyorders.online_payment_timeout_minutes', 30);
				$temp->payment_poll_deadline_at = CarbonImmutable::now()->addMinutes($timeoutMinutes);
				$temp->payment_poll_attempts = 0;
			}

			$temp->save();

			// Queue next step:
			// - For COD and already-final non-COD orders, go directly to validation.
			// - For non-COD pending_payment orders, wait for final payment status via polling job.
			if ($shouldWaitForPayment) {
				Log::info('EasyOrders webhook: queued WaitForPaymentStatusJob', [
					'temp_order_id' => $temp->id,
					'store_id' => $temp->store_id,
				]);
				WaitForPaymentStatusJob::dispatch($temp->id)->onQueue('default');
			} else {
				Log::info('EasyOrders webhook: queued ValidateTempOrderJob', [
					'temp_order_id' => $temp->id,
					'store_id' => $temp->store_id,
				]);
				ValidateTempOrderJob::dispatch($temp->id)->onQueue('default');
			}

			return $temp;
		});

		// Mark webhook log as successfully handled.
		$log->http_status = 200;
		$log->error = null;
		$log->save();

		return $temp;
	}

	public function fetchOrderDetails(EasyOrdersStore $store, string $externalOrderId): array
	{
		$baseUrl = rtrim((string) Config::get('easyorders.base_url'), '/');
		$orderPath = trim((string) Config::get('easyorders.order_details_path', '/external-apps/orders'), '/');
		$url = $baseUrl . '/' . $orderPath . '/' . urlencode($externalOrderId);

		$response = Http::withHeaders([
			'Api-Key' => (string) $store->api_key,
		])
			->acceptJson()
			->timeout(10)
			->get($url);

		if (!$response->successful()) {
			abort(502, 'Failed to fetch order details from EasyOrders.');
		}

		return $response->json() ?? [];
	}

	/**
	 * Build normalized snapshot for an EasyOrders order payload.
	 */
	public function normalizeOrderPayload(array $payload, string $externalOrderId): array
	{
		$now = CarbonImmutable::now();
		$createdAt = Arr::get($payload, 'created_at');
		$createdDay = $createdAt ? (new CarbonImmutable($createdAt))->toDateString() : $now->toDateString();

		$cartItems = Arr::get($payload, 'cart_items', []);
		$totalCost = Arr::get($payload, 'total_cost');
		$shippingCost = Arr::get($payload, 'shipping_cost');
		$cost = Arr::get($payload, 'cost');
		$expense = Arr::get($payload, 'expense');
		$couponDiscount = Arr::get($payload, 'coupon_discount', 0);

		$normalized = [
			'external_order_id' => $externalOrderId,
			'short_id' => Arr::get($payload, 'short_id'),
			'timestamps' => [
				'created_at' => Arr::get($payload, 'created_at'),
				'updated_at' => Arr::get($payload, 'updated_at'),
				'created_day' => $createdDay,
			],
			'store' => [
				'external_store_id' => Arr::get($payload, 'store_id'),
			],
			'guest_id' => Arr::get($payload, 'guest_id'),
			'customer' => [
				'full_name' => Arr::get($payload, 'full_name'),
				'phone' => Arr::get($payload, 'phone'),
				'government' => Arr::get($payload, 'government'),
				'address' => Arr::get($payload, 'address'),
			],
			'network' => [
				'ip' => Arr::get($payload, 'ip'),
				'ip_country' => Arr::get($payload, 'ip_country'),
			],
			'payment_method' => Arr::get($payload, 'payment_method'),
			'totals' => [
				'cost'            => $cost,
				'shipping_cost'   => $shippingCost,
				'total_cost'      => $totalCost,
				'expense'         => $expense,
				'coupon_discount' => $couponDiscount,
			],
			'status' => Arr::get($payload, 'status'),
			'metadata' => Arr::get($payload, 'metadata', []),
			'items' => $this->normalizeCartItems($cartItems),
		];

		return [
			'normalized' => $normalized,
			'created_day' => $createdDay,
			'cost' => $cost,
			'shipping_cost' => $shippingCost,
			'total_cost' => $totalCost,
			'expense' => $expense,
			'coupon_discount' => $couponDiscount,
		];
	}

	/**
	 * Normalize EasyOrders cart items into our internal structure.
	 *
	 * This method also supports composite SKUs where a single SKU encodes
	 * multiple products joined by "+", for example:
	 *   "Forev-Q1S+Forev-w502+OP1-headphone"
	 *
	 * Behaviour for composite SKUs:
	 * - Each part becomes its own normalized item.
	 * - Each split item keeps the same quantity as the original cart line.
	 * - External per-item price is ignored: we set the external price used
	 *   for price-policy checks to null so that internal catalog prices drive
	 *   the final order amounts.
	 */
	private function normalizeCartItems(array $cartItems): array
	{
		$normalizedItems = [];

		foreach ($cartItems as $item) {
			$product = Arr::get($item, 'product', []);
			$variant = Arr::get($item, 'variant', []);

			$base = [
				'external_item_id' => Arr::get($item, 'id'),
				'price' => Arr::get($item, 'price'),
				'quantity' => Arr::get($item, 'quantity'),
				'product' => [
					'external_id' => Arr::get($product, 'id'),
					'name' => Arr::get($product, 'name'),
					'sku' => Arr::get($product, 'sku'),
					'slug' => Arr::get($product, 'slug'),
					'thumb' => Arr::get($product, 'thumb'),
					'images' => Arr::get($product, 'images', []),
				],
				'variant' => [
					'external_id' => Arr::get($variant, 'id'),
					'variant_sku' => Arr::get($variant, 'taager_code'),
					'variation_props' => Arr::get($variant, 'variation_props', []),
				],
				'resolved' => [
					'internal_product_id' => null,
					'internal_variant_id' => null,
					'price_policy' => [
						'external_price' => Arr::get($item, 'price'),
						'internal_price' => null,
						'mismatch' => false,
					],
				],
			];

			$variantSku = Arr::get($variant, 'taager_code');
			$productSku = Arr::get($product, 'sku');
			$sourceSku = $variantSku ?: $productSku;

			if (is_string($sourceSku) && str_contains($sourceSku, '+')) {
				$parts = array_filter(array_map('trim', explode('+', $sourceSku)), static fn ($part) => $part !== '');

				if (empty($parts)) {
					$normalizedItems[] = $base;
					continue;
				}

				$index = 1;
				foreach ($parts as $part) {
					$splitItem = $base;

					// Help debugging by making external_item_id unique per split part when possible.
					if ($splitItem['external_item_id'] !== null) {
						$splitItem['external_item_id'] = (string) $splitItem['external_item_id'] . '-' . $index;
					}

					if ($variantSku) {
						$splitItem['variant']['variant_sku'] = $part;
					} else {
						$splitItem['product']['sku'] = $part;
					}

					// For composite SKUs, ignore external per-item price and rely on internal catalog prices.
					$splitItem['price'] = null;
					$splitItem['resolved']['price_policy']['external_price'] = null;

					$normalizedItems[] = $splitItem;
					$index++;
				}

				continue;
			}

			$normalizedItems[] = $base;
		}

		return $normalizedItems;
	}
}


