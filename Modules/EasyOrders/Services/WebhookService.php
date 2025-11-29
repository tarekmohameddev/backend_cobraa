<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Modules\EasyOrders\Entities\EasyOrdersStore;
use Modules\EasyOrders\Entities\EasyOrdersTempOrder;
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

		$externalOrderId = (string) Arr::get($payload, 'id');
		if (!$externalOrderId) {
			abort(422, 'Missing order id');
		}

		$duplicate = $this->tempOrderRepository->findDuplicate($store->id, $externalOrderId);
		if ($duplicate) {
			// Idempotent: return existing and ensure validation job is queued (optional)
			return $duplicate;
		}

		// Fetch full order details from EasyOrders API instead of relying solely on webhook payload
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

		return DB::transaction(function () use ($store, $payload, $normalizedData, $shouldWaitForPayment, $paymentMethod, $externalStatus) {
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
				WaitForPaymentStatusJob::dispatch($temp->id)->onQueue('default');
			} else {
				ValidateTempOrderJob::dispatch($temp->id)->onQueue('default');
			}

			return $temp;
		});
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
			'items' => array_map(function ($item) {
				$product = Arr::get($item, 'product', []);
				$variant = Arr::get($item, 'variant', []);
				return [
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
			}, $cartItems),
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
}


