<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Modules\EasyOrders\Entities\EasyOrdersTempOrder;
use Modules\EasyOrders\Services\WebhookService;
use Modules\EasyOrders\Jobs\ValidateTempOrderJob;

class WaitForPaymentStatusJob implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	public function __construct(public int $tempOrderId)
	{
		$this->onQueue('default');
	}

	public function handle(WebhookService $webhookService): void
	{
		/** @var EasyOrdersTempOrder|null $temp */
		$temp = EasyOrdersTempOrder::query()->with('store')->find($this->tempOrderId);
		if (!$temp) {
			return;
		}

		if ($temp->status !== 'waiting_payment') {
			return;
		}

		$now = CarbonImmutable::now();
		$timeoutMinutes = (int) Config::get('easyorders.online_payment_timeout_minutes', 30);

		$deadline = $temp->payment_poll_deadline_at
			? CarbonImmutable::parse($temp->payment_poll_deadline_at)
			: ($temp->created_at ? CarbonImmutable::parse($temp->created_at)->addMinutes($timeoutMinutes) : $now->addMinutes($timeoutMinutes));

		if ($now->greaterThan($deadline)) {
			// On timeout, import the order as unpaid instead of marking as import_failed
			// Update payload and normalized data to reflect unpaid status
			$payload = $temp->payload ?? [];
			$normalized = $temp->normalized ?? [];
			
			// Mark the order as having payment timeout in normalized metadata
			$normalized['metadata'] = $normalized['metadata'] ?? [];
			$normalized['metadata']['payment_timeout'] = true;
			$normalized['metadata']['payment_timeout_minutes'] = $timeoutMinutes;
			
			// Set status to pending_payment to indicate unpaid
			$payload['status'] = 'pending_payment';
			$normalized['status'] = 'pending_payment';

			DB::transaction(function () use ($temp, $payload, $normalized) {
				$temp->status = 'pending';
				$temp->failure_reason = null;
				$temp->payload = $payload;
				$temp->normalized = $normalized;
				$temp->payment_poll_deadline_at = null;
				$temp->save();
			});

			// Dispatch validation job to proceed with normal import flow
			ValidateTempOrderJob::dispatch($temp->id)->onQueue('default');
			return;
		}

		$store = $temp->store;
		if (!$store) {
			DB::transaction(function () use ($temp) {
				$temp->status = 'import_failed';
				$reason = 'Missing EasyOrders store while waiting for payment status';
				$temp->failure_reason = $temp->failure_reason
					? $temp->failure_reason.'; '.$reason
					: $reason;
				$temp->save();
			});

			return;
		}

		try {
			$payload = $webhookService->fetchOrderDetails($store, $temp->external_order_id);
		} catch (\Throwable $e) {
			$this->reschedule($temp, $deadline);
			return;
		}

		$externalStatus = Arr::get($payload, 'status');
		$paymentMethod = Arr::get($payload, 'payment_method');

		if ($externalStatus === 'pending_payment') {
			$this->reschedule($temp, $deadline);
			return;
		}

		if (in_array($externalStatus, ['paid', 'paid_failed'], true)) {
			$normalizedData = $webhookService->normalizeOrderPayload($payload, $temp->external_order_id);

			DB::transaction(function () use ($temp, $payload, $normalizedData) {
				$createdDay = $normalizedData['created_day'];
				$cost = $normalizedData['cost'];
				$shippingCost = $normalizedData['shipping_cost'];
				$totalCost = $normalizedData['total_cost'];
				$expense = $normalizedData['expense'];
				$normalized = $normalizedData['normalized'];

				$temp->status = 'pending';
				$temp->failure_reason = null;
				$temp->cost = $cost !== null ? (float) $cost : null;
				$temp->shipping_cost = $shippingCost !== null ? (float) $shippingCost : null;
				$temp->total_cost = $totalCost !== null ? (float) $totalCost : null;
				$temp->expense = $expense !== null ? (float) $expense : null;
				$temp->created_day = $createdDay;
				$temp->payload = $payload;
				$temp->normalized = $normalized;
				$temp->payment_poll_deadline_at = null;
				$temp->save();
			});

			ValidateTempOrderJob::dispatch($temp->id)->onQueue('default');
			return;
		}

		// Unexpected status: treat as still waiting until timeout.
		$this->reschedule($temp, $deadline);
	}

	private function reschedule(EasyOrdersTempOrder $temp, CarbonImmutable $deadline): void
	{
		$interval = (int) Config::get('easyorders.online_payment_poll_interval_seconds', 60);

		$temp->payment_poll_attempts = (int) $temp->payment_poll_attempts + 1;
		$temp->save();

		$delaySeconds = max($interval, 1);

		static::dispatch($temp->id)->delay(now()->addSeconds($delaySeconds));
	}
}


