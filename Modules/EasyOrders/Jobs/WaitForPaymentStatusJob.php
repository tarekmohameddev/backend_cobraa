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
use Illuminate\Support\Facades\Log;
use Modules\EasyOrders\Entities\EasyOrdersTempOrder;
use Modules\EasyOrders\Services\WebhookService;

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
			Log::info('EasyOrders wait-payment: temp order not found', [
				'temp_order_id' => $this->tempOrderId,
			]);
			return;
		}

		if ($temp->status !== 'waiting_payment') {
			Log::info('EasyOrders wait-payment: skipping, status no longer waiting_payment', [
				'temp_order_id' => $temp->id,
				'status' => $temp->status,
			]);
			return;
		}

		$now = CarbonImmutable::now();
		$timeoutMinutes = (int) Config::get('easyorders.online_payment_timeout_minutes', 30);

		$deadline = $temp->payment_poll_deadline_at
			? CarbonImmutable::parse($temp->payment_poll_deadline_at)
			: ($temp->created_at ? CarbonImmutable::parse($temp->created_at)->addMinutes($timeoutMinutes) : $now->addMinutes($timeoutMinutes));

		if ($now->greaterThan($deadline)) {
			DB::transaction(function () use ($temp, $timeoutMinutes) {
				$temp->status = 'import_failed';
				$reason = 'Payment status timeout after '.$timeoutMinutes.' minutes';
				$temp->failure_reason = $temp->failure_reason
					? $temp->failure_reason.'; '.$reason
					: $reason;
				$temp->save();
			});

			Log::info('EasyOrders wait-payment: timeout reached, marking import_failed', [
				'temp_order_id' => $temp->id,
				'external_order_id' => $temp->external_order_id,
			]);

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

			Log::info('EasyOrders wait-payment: missing store, marking import_failed', [
				'temp_order_id' => $temp->id,
				'external_order_id' => $temp->external_order_id,
			]);

			return;
		}

		try {
			$payload = $webhookService->fetchOrderDetails($store, $temp->external_order_id);
		} catch (\Throwable $e) {
			Log::info('EasyOrders wait-payment: error fetching order details, will retry', [
				'temp_order_id' => $temp->id,
				'external_order_id' => $temp->external_order_id,
				'error' => $e->getMessage(),
			]);

			$this->reschedule($temp, $deadline);
			return;
		}

		$externalStatus = Arr::get($payload, 'status');
		$paymentMethod = Arr::get($payload, 'payment_method');

		Log::info('EasyOrders wait-payment: polled EasyOrders status', [
			'temp_order_id' => $temp->id,
			'external_order_id' => $temp->external_order_id,
			'external_status' => $externalStatus,
			'payment_method' => $paymentMethod,
		]);

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

			Log::info('EasyOrders wait-payment: final payment status reached, dispatching validation', [
				'temp_order_id' => $temp->id,
				'external_order_id' => $temp->external_order_id,
				'final_status' => $externalStatus,
			]);

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

		Log::info('EasyOrders wait-payment: rescheduling poll', [
			'temp_order_id' => $temp->id,
			'external_order_id' => $temp->external_order_id,
			'next_attempt_in_seconds' => $delaySeconds,
			'payment_poll_attempts' => $temp->payment_poll_attempts,
			'deadline' => $deadline->toIso8601String(),
		]);

		static::dispatch($temp->id)->delay(now()->addSeconds($delaySeconds));
	}
}


