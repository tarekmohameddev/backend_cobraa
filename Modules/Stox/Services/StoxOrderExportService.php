<?php

declare(strict_types=1);

namespace Modules\Stox\Services;

use App\Models\Order;
use App\Models\User;
use App\Models\Area;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Modules\Stox\Entities\StoxAccount;
use Modules\Stox\Entities\StoxOrder;
use Modules\Stox\Traits\LogsStoxOperations;
use Throwable;

class StoxOrderExportService
{
    use LogsStoxOperations;

    public function __construct(
        private readonly StoxApiService $apiService,
    ) {
    }

    /**
     * @param array<string, mixed> $overrideData
     * @return array{success: bool, message: string, data?: array}
     */
    public function export(
        Order $order,
        StoxAccount $account,
        array $overrideData = [],
        ?User $triggeredBy = null,
        string $triggerType = 'manual'
    ): array {
        if (!$account->isActive()) {
            return [
                'success' => false,
                'message' => 'Stox account is inactive.',
            ];
        }
        
        $order->loadMissing([
            'orderDetails.stock.product',
            'children.orderDetails.stock.product',
            'user',
            'myAddress',
        ]);

        try {
            $payload = $this->buildPayload($order, $account, $overrideData);
            $stoxOrder = $this->getOrCreateStoxOrder($order, $account, $payload);
        } catch (RuntimeException $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }

        $startedAt = microtime(true);

        try {
            $result = $this->apiService->sendOrder($account, $payload);
            $executionTime = (int) ((microtime(true) - $startedAt) * 1000);

            if ($result['success']) {
                $this->handleSuccessfulExport($stoxOrder, $result['data'] ?? []);

                $this->logStoxOperation(
                    'order_export_success',
                    $account,
                    $stoxOrder,
                    $order->id,
                    $triggeredBy,
                    $payload,
                    $result['data'],
                    $result['status'],
                    $triggerType,
                    executionTimeMs: $executionTime
                );

                return [
                    'success' => true,
                    'message' => 'Order exported to Stox successfully.',
                    'data' => $stoxOrder->fresh(),
                ];
            }

            $this->markExportFailure($stoxOrder, $result['error'] ?? 'Unknown Stox error.');

            $this->logStoxOperation(
                'order_export_failed',
                $account,
                $stoxOrder,
                $order->id,
                $triggeredBy,
                $payload,
                $result['data'],
                $result['status'],
                $triggerType,
                $result['error'],
                executionTimeMs: $executionTime
            );

            return [
                'success' => false,
                'message' => $result['error'] ?? 'Failed to export order to Stox.',
            ];
        } catch (Throwable $exception) {
            $this->markExportFailure($stoxOrder, $exception->getMessage());

            $this->logStoxOperation(
                'order_export_failed',
                $account,
                $stoxOrder,
                $order->id,
                $triggeredBy,
                $payload,
                null,
                null,
                $triggerType,
                $exception->getMessage(),
                $exception->getTraceAsString()
            );

            return [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $overrideData
     * @return array<string, mixed>
     */
    private function buildPayload(Order $order, StoxAccount $account, array $overrideData): array
    {
        $address = is_array($order->address) ? $order->address : [];
        $settings = $account->settings ?? [];
        $paymentType = $this->resolvePaymentType($order, $account, $overrideData);
        $totalQuantity = (int) $order->orderDetails->sum('quantity');

        // Resolve area_id: prefer explicit override, then order address; no hard-coded default.
        $areaId = Arr::get($overrideData, 'area_id', Arr::get($address, 'area_id'));

        // Resolve area name:
        // 1) explicit override
        // 2) address-stored area_name / city
        // 3) lookup Area model by id and use its translation title
        $areaName = Arr::get($overrideData, 'area_name')
            ?? Arr::get($address, 'area_name')
            ?? Arr::get($address, 'city');

        if (!$areaName && $areaId) {
            /** @var Area|null $area */
            $area = Area::query()->with('translation')->find($areaId);
            if ($area) {
                $areaName = $area->translation?->title
                    ?? $area->translations()->first()?->title
                    ?? null;
            }
        }

        if (!$areaName) {
            $areaName = (string) $areaId;
        }

        // Resolve address to a flat string as required by Stox.
        // In some cases `$order->address['address']` itself may be a nested array
        // like ['address' => '...'], so we normalise that here.
        $rawAddress = Arr::get($overrideData, 'address', Arr::get($address, 'address'));
        if (is_array($rawAddress)) {
            $rawAddress = Arr::get($rawAddress, 'address') ?? null;
        }

        // Stox seems to always expect a `cod_amount` key, even for CC payments.
        // For COD: use the order total. For CC (prepaid): send 0 as a safe default.
        $defaultCodAmount = $paymentType === 'COD'
            ? round((float) $order->total_price, 2)
            : 0.0;

        $payload = [
            'customer_name' => Arr::get($overrideData, 'customer_name', $order->username ?? $order->user?->firstname),
            'address' => $rawAddress,
            'mobile_1' => Arr::get($overrideData, 'mobile_1', $order->phone ?? $order->user?->phone),
            'mobile_2' => Arr::get($overrideData, 'mobile_2', Arr::get($settings, 'mobile_2')),
            'area_id' => $areaId,
            'area_name' => $areaName,
            'email' => Arr::get($overrideData, 'email', $order->user?->email),
            'reference_number' => (string) Arr::get($overrideData, 'reference_number', $order->id),
            // Stox seems to validate against `payment_type`, but documentation
            // also mentions `payment_type_id`, so we send both.
            'payment_type' => $paymentType,
            'payment_type_id' => $paymentType,
            'cod_amount' => Arr::get(
                $overrideData,
                'cod_amount',
                $defaultCodAmount
            ),
            'qty' => Arr::get(
                $overrideData,
                'qty',
                $totalQuantity > 0 ? $totalQuantity : null
            ),
            'note' => Arr::get($overrideData, 'note', $order->note),
            'can_open' => Arr::get($overrideData, 'can_open', Arr::get($settings, 'can_open')),
            'is_part_delivered' => Arr::get($overrideData, 'is_part_delivered'),
            'return_qty' => Arr::get($overrideData, 'return_qty'),
            'products' => $this->buildProductPayload($order, $overrideData),
        ];

        return array_filter(
            array_merge($payload, Arr::get($overrideData, 'extras', [])),
            static fn($value) => $value !== null && $value !== ''
        );
    }

    /**
     * @param array<string, mixed> $overrideData
     * @return array<int, array<string, mixed>>
     */
    private function buildProductPayload(Order $order, array $overrideData): array
    {
        if ($customProducts = Arr::get($overrideData, 'products')) {
            return collect($customProducts)
                ->map(static function (array $product): array {
                    return [
                        'id' => null,
                        'sku' => $product['sku'] ?? null,
                        'qty' => (int) ($product['qty'] ?? 0),
                        'item_price' => $product['item_price'] ?? null,
                    ];
                })
                ->all();
        }

        $details = $order->orderDetails;

        if ($details->isEmpty() && $order->relationLoaded('children')) {
            $details = $order->children
                ->flatMap(static fn (Order $child) => $child->orderDetails);
        }

        /** @var Collection<int, array<string, mixed>> $products */
        $products = $details->map(function ($detail): ?array {
            $stock = $detail->stock;

            if (!$stock?->sku) {
                return null;
            }

            return [
                'id' => null,
                'sku' => $stock->sku,
                'qty' => (int) $detail->quantity,
                'item_price' => round((float) ($stock->price ?? $detail->origin_price), 2),
            ];
        })->filter()->values();

        if ($products->isEmpty()) {
            throw new \RuntimeException('Order does not contain mappable products for Stox.');
        }

        return $products->all();
    }

    private function resolvePaymentType(Order $order, StoxAccount $account, array $overrideData): string
    {
        $override = Arr::get($overrideData, 'payment_type_id');
        if ($override) {
            return strtoupper((string) $override);
        }

        // Determine payment type based on the latest transaction status:
        // - progress => COD
        // - paid     => CC
        $order->loadMissing('transactions');

        $transaction = $order->transactions
            ->sortByDesc('id')
            ->first();

        $status = $transaction?->status;

        if ($status === \App\Models\Transaction::STATUS_PAID) {
            return 'CC';
        }

        // Treat progress and everything else as COD by default.
        return 'COD';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function getOrCreateStoxOrder(Order $order, StoxAccount $account, array $payload): StoxOrder
    {
        return DB::transaction(static function () use ($order, $account, $payload): StoxOrder {
            /** @var StoxOrder $stoxOrder */
            $stoxOrder = StoxOrder::query()->firstOrCreate(
                [
                    'stox_account_id' => $account->id,
                    'order_id' => $order->id,
                ],
                [
                    'reference_number' => $payload['reference_number'] ?? (string) $order->id,
                ]
            );

            $stoxOrder->forceFill([
                'reference_number' => $payload['reference_number'] ?? (string) $order->id,
                'export_status' => 'exporting',
                'export_payload' => $payload,
                'retry_count' => $stoxOrder->retry_count + 1,
            ])->save();

            return $stoxOrder;
        });
    }

    /**
     * @param array<string, mixed> $response
     */
    private function handleSuccessfulExport(StoxOrder $stoxOrder, array $response): void
    {
        $firstRecord = collect(data_get($response, 'data', []))->first();

        $externalId = data_get($firstRecord, 'id');
        $awbNumber = data_get($firstRecord, 'awb_number');

        $stoxOrder->markExported(
            $externalId !== null ? (string) $externalId : null,
            $awbNumber !== null ? (string) $awbNumber : null,
            $response
        );
    }

    private function markExportFailure(StoxOrder $stoxOrder, string $message): void
    {
        $stoxOrder->forceFill([
            'export_status' => 'failed',
            'last_error' => $message,
        ])->save();
    }
}

