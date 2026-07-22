<?php
declare(strict_types=1);

namespace App\Services\OrderService;

use App\Helpers\NotificationHelper;
use App\Helpers\OrderHelper;
use App\Helpers\ResponseError;
use App\Models\Currency;
use App\Models\Language;
use App\Models\PushNotification;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Settings;
use App\Models\User;
use App\Services\CoreService;
use App\Services\EmailSettingService\EmailSendService;
use App\Services\TransactionService\TransactionService;
use App\Traits\Notification;
use DB;
use Exception;
use Illuminate\Support\Arr;
use Modules\OrderEnhancements\Services\OrderActivityLogService;
use Throwable;

class OrderService extends CoreService
{
    use Notification;

    protected function getModelClass(): string
    {
        return Order::class;
    }

    private function with(): array
    {
        $locale = Language::where('default', 1)->first()?->locale;

        return [
            'user',
            'review',
            'pointHistories',
            'currency',
            'deliveryman',
            'coupon',
            'shop:id,lat_long,tax,background_img,logo_img,uuid,phone,user_id',
            'shop.translation' => fn($q) => $q
                ->select([
                    'id',
                    'shop_id',
                    'locale',
                    'title',
                    'address',
                ])
                ->where('locale', $this->language)
                ->orWhere('locale', $locale),

            'orderDetails.stock.discount' => fn($q) => $q->where('start', '<=', today())
                ->where('end', '>=', today())
                ->where('active', 1),

            'orderDetails.stock.product.translation' => fn($q) => $q
                ->select([
                    'id',
                    'product_id',
                    'locale',
                    'title',
                ])
                ->where('locale', $this->language)
                ->orWhere('locale', $locale),

            'orderDetails.stock.stockExtras.value',
            'orderDetails.stock.stockExtras.group.translation' => function ($q) use ($locale) {
                $q->select('id', 'extra_group_id', 'locale', 'title')
                    ->where('locale', $this->language)
                    ->orWhere('locale', $locale);
            },

            'orderDetails.replaceStock.discount' => fn($q) => $q->where('start', '<=', today())
                ->where('end', '>=', today())
                ->where('active', 1),

            'orderDetails.replaceStock.product.translation' => fn($q) => $q
                ->select([
                    'id',
                    'product_id',
                    'locale',
                    'title',
                ])
                ->where('locale', $this->language)
                ->orWhere('locale', $locale),

            'orderDetails.replaceStock.stockExtras.value',
            'orderDetails.replaceStock.stockExtras.group.translation' => function ($q) use ($locale) {
                $q->select('id', 'extra_group_id', 'locale', 'title')
                    ->where('locale', $this->language)
                    ->orWhere('locale', $locale);
            },
            'orderRefunds',
            'transaction.paymentSystem',
            'galleries',
            'myAddress',
        ];
    }

    /**
     * @param array $data
     * @return array
     */
    public function create(array $data): array
    {
        try {
            OrderHelper::checkPhoneIfRequired($data, $this->language);

            $orders = DB::transaction(function () use ($data) {

                $orders = match (true) {
                    isset($data['data'])    => (new POSOrderService)->create($data),
                    isset($data['cart_id']) => (new CartOrderService)->create($data, $data['notes'] ?? []),
                    default                 => throw new Exception('error data'),
                };

                $currency = Currency::currenciesList()->where('id', data_get($data, 'currency_id'))->first();

                if (empty($currency)) {
                    /** @var Currency $currency */
                    $currency = Currency::currenciesList()->where('default', 1)->first();
                }

                $tips = data_get($data, 'tips', 0);

                if ($tips > 0 && $currency?->rate > 0) {
                    $tips = data_get($data, 'tips', 0) / $currency->rate / count($orders);
                }

                foreach ($orders as $key => $order) {

                    if ($tips > 0) {
                        $data['tips'] = $tips;
                    }

                    $this->calculateOrder($order, $data, false, count($orders));

                    /** @var Order $order */
                    $order = $order->fresh($this->with());

                    $orders[$key] = $order;

                    if (in_array($order->status, $order->shop?->email_statuses ?? []) && $order->user?->email) {
                        (new EmailSendService)->sendOrder($order);
                    }

                }

                return $orders;
            });

            try {
                $actor = auth('sanctum')->user();
                $logger = new OrderActivityLogService();

                foreach ($orders as $order) {
                    $logger->logCreation($order, $actor);
                }
            } catch (Throwable) {
                // Audit logging must never affect order flow.
            }

            return [
                'status'  => true,
                'message' => ResponseError::NO_ERROR,
                'data'    => $orders
            ];

        } catch (Throwable $e) {
            $this->error($e);

            return [
                'status'  => false,
                'message' => $e->getMessage(),
                'code'    => ResponseError::ERROR_501,
            ];
        }
    }

    /**
     * @param int $id
     * @param array $data
     * @return array
     */
    public function update(int $id, array $data): array
    {
        try {
//            OrderHelper::checkPhoneIfRequired($data, $this->language);

            $itemsDiff = null;

            /** @var Order $order */
            $order = DB::transaction(function () use ($data, $id, &$itemsDiff) {

                /** @var Order $order */
                $order = $this->model()
                    ->with([
                        'orderDetails',
                        'transaction'
                    ])
                    ->find($id);

                if (!$order) {
                    throw new Exception(__('errors.' . ResponseError::ORDER_NOT_FOUND, locale: $this->language));
                }

                // Preserve any "manual" discount already applied on the order.
                // total_discount is used both for item-level discounts (sum of order_details.discount)
                // and admin-applied manual discounts. On update we rebuild order_details which would
                // otherwise reset total_discount to only item discounts.
                $itemsDiscountBeforeUpdate = (double)$order->orderDetails->sum('discount');
                $manualDiscountBeforeUpdate = max((double)$order->total_discount - $itemsDiscountBeforeUpdate, 0);

                $before = $order->orderDetails
                    ->mapWithKeys(static function ($detail) {
                        $key = ((int)$detail->stock_id) . ':' . ((int)(bool)($detail->bonus ?? false));

                        return [$key => [
                            'stock_id' => (int)$detail->stock_id,
                            'bonus' => (bool)($detail->bonus ?? false),
                            'quantity' => (int)$detail->quantity,
                        ]];
                    });

                $orderData = Arr::except($data, ['products', 'images']);
                $order->update($orderData);

                if (data_get($data, 'images.0')) {

                    $order->galleries()->delete();
                    $order->update(['img' => data_get($data, 'images.0')]);
                    $order->uploads(data_get($data, 'images'));

                }

                $order = (new OrderDetailService)->create($order, data_get($data, 'products', []));

                $after = $order->orderDetails()
                    ->get(['stock_id', 'bonus', 'quantity'])
                    ->mapWithKeys(static function ($detail) {
                        $key = ((int)$detail->stock_id) . ':' . ((int)(bool)($detail->bonus ?? false));

                        return [$key => [
                            'stock_id' => (int)$detail->stock_id,
                            'bonus' => (bool)($detail->bonus ?? false),
                            'quantity' => (int)$detail->quantity,
                        ]];
                    });

                $allKeys = $before->keys()->merge($after->keys())->unique();

                $linesAdded = 0;
                $unitsAdded = 0;
                $linesRemoved = 0;
                $unitsRemoved = 0;
                $details = [
                    'added' => [],
                    'removed' => [],
                    'changed' => [],
                ];

                foreach ($allKeys as $key) {
                    $b = (int) data_get($before->get($key), 'quantity', 0);
                    $a = (int) data_get($after->get($key), 'quantity', 0);

                    if ($b <= 0 && $a > 0) {
                        $linesAdded++;
                        $unitsAdded += $a;
                        $details['added'][] = array_merge($after->get($key), ['key' => $key]);
                        continue;
                    }

                    if ($b > 0 && $a <= 0) {
                        $linesRemoved++;
                        $unitsRemoved += $b;
                        $details['removed'][] = array_merge($before->get($key), ['key' => $key]);
                        continue;
                    }

                    if ($b > 0 && $a > 0 && $a !== $b) {
                        $delta = $a - $b;

                        if ($delta > 0) {
                            $unitsAdded += $delta;
                        } else {
                            $unitsRemoved += abs($delta);
                        }

                        $details['changed'][] = [
                            'key' => $key,
                            'stock_id' => (int) data_get($after->get($key), 'stock_id'),
                            'bonus' => (bool) data_get($after->get($key), 'bonus', false),
                            'before_quantity' => $b,
                            'after_quantity' => $a,
                            'delta' => $delta,
                        ];
                    }
                }

                $itemsDiff = [
                    'lines_added' => $linesAdded,
                    'units_added' => $unitsAdded,
                    'lines_removed' => $linesRemoved,
                    'units_removed' => $unitsRemoved,
                    'details' => $details,
                ];

                $this->calculateOrder($order, array_merge($data, [
                    '_manual_discount_before_update' => $manualDiscountBeforeUpdate,
                ]), true);

                return $order;
            });

            try {
                if (is_array($itemsDiff)) {
                    $hasChanges = ((int) data_get($itemsDiff, 'lines_added', 0) > 0)
                        || ((int) data_get($itemsDiff, 'lines_removed', 0) > 0)
                        || ((int) data_get($itemsDiff, 'units_added', 0) > 0)
                        || ((int) data_get($itemsDiff, 'units_removed', 0) > 0);

                    if ($hasChanges) {
                        $actor = auth('sanctum')->user();
                        (new OrderActivityLogService)->logItemsModified(
                            $order,
                            $actor,
                            (int) data_get($itemsDiff, 'lines_added', 0),
                            (int) data_get($itemsDiff, 'units_added', 0),
                            (int) data_get($itemsDiff, 'lines_removed', 0),
                            (int) data_get($itemsDiff, 'units_removed', 0),
                            (array) data_get($itemsDiff, 'details', []),
                        );
                    }
                }
            } catch (Throwable) {
                // Audit logging must never affect order flow.
            }

            return [
                'status'  => true,
                'message' => ResponseError::NO_ERROR,
                'data'    => $order->fresh($this->with())
            ];

        } catch (Throwable $e) {
            $this->error($e);
            return [
                'status'  => false,
                'message' => $e->getMessage(),
                'code'    => ResponseError::ERROR_502
            ];
        }
    }

    /**
     * @param Order $order
     * @param array $data
     * @param bool $isUpdate
     * @param int $ordersCount
     * @return void
     * @throws Exception
     */
    private function calculateOrder(Order $order, array $data, bool $isUpdate = false, int $ordersCount = 0): void
    {
        $locale = Language::where('default', 1)->first()?->locale;

        /** @var Order $order */
        $order = $order->fresh([
            'shop:id,tax,percentage,visibility',
            'shop.translation'   => fn($q) => $q
                ->when($this->language, function ($q) use ($locale) {
                    $q->where(fn($q) => $q->where('locale', $this->language)->orWhere('locale', $locale));
                }),
            'shop.subscription.subscription',
        ]);

        $isSubscribe = (int)Settings::where('key', 'by_subscription')->first()?->value;

        $totalPrice = $order->orderDetails->sum('total_price');
        $itemsDiscount = $order->orderDetails->sum('discount');
        $manualDiscount = (double)data_get($data, '_manual_discount_before_update', 0);

        $shopTax = max($totalPrice / 100 * $order->shop?->tax, 0);

        $deliveryFee = [];

        OrderHelper::checkShopDelivery($order->shop, $data, $this->language, $deliveryFee);

        $couponPrice = collect();
        $couponPrice = OrderHelper::checkCoupon($data, $order->shop_id, $totalPrice, $order->rate, $couponPrice, $deliveryFee);

        foreach ($couponPrice as $coupon) {
            $this->createOrderCoupon($coupon['coupon'], $order, $totalPrice);
        }

        $totalPrice += $shopTax;

        $percent = $order->shop?->percentage;

        $commissionFee = 0;

        if (!$isSubscribe && $percent > 0) {
            $commissionFee = max(($totalPrice / 100 * $percent), 0);
        }

        if ($isSubscribe) {

            $orderLimit = $order->shop?->subscription?->subscription?->order_limit;

            $shopOrdersCount = DB::table('orders')
                ->select(['shop_id'])
                ->where('shop_id', $order->shop_id)
                ->count('shop_id');

            if ($orderLimit < $shopOrdersCount) {
                $order->shop?->update([
                    'visibility' => 0
                ]);
            }

        }

        $serviceFee = (double)Settings::where('key', 'service_fee')->first()?->value ?: 0;

        $serviceFee = !$isUpdate
            ? $serviceFee > 0 ? $serviceFee / $ordersCount : $serviceFee
            : $order->service_fee;

        $totalPrice += $serviceFee;

        $couponPriceSum = collect($couponPrice)->sum('price');

        $deliveryFeeSum = collect($deliveryFee)->sum('price');
        // If we couldn't recalculate delivery fee (e.g. missing delivery_price_id/city/area),
        // keep using the current order->delivery_fee in total calculation to avoid dropping it.
        $deliveryFeeToApply = $deliveryFeeSum > 0 ? $deliveryFeeSum : (double)$order->delivery_fee;

        $totalPrice += $deliveryFeeToApply;
        $totalPrice -= $couponPriceSum;

        // Apply preserved manual discount after rebuilding totals.
        if ($manualDiscount > 0) {
            $totalPrice = max($totalPrice - $manualDiscount, 0);
        }

        $order->update([
            'total_price'       => $totalPrice,
            'tips'              => $data['tips'] ?? $order->tips,
            'commission_fee'    => $commissionFee,
            'total_discount'    => max((double)$itemsDiscount + $manualDiscount, 0),
            'total_tax'         => $shopTax,
            'delivery_fee'      => $deliveryFeeSum === 0 ? $order->delivery_fee : $deliveryFeeSum,
            'coupon_price'      => $couponPriceSum === 0 ? $order->coupon_price : $couponPriceSum,
            'service_fee'       => $serviceFee,
        ]);

        if (data_get($data, 'payment_id') && !data_get($data, 'trx_status')) {

            $data['payment_sys_id'] = data_get($data, 'payment_id');

            $result = (new TransactionService)->orderTransaction($order->id, $data);

            if (!data_get($result, 'status')) {
                throw new Exception(data_get($result, 'message'));
            }

        }

        OrderHelper::updateUserOrderStat($order);
    }

    /**
     * @param Coupon $coupon
     * @param Order $order
     * @param $totalPrice
     * @return float|int|null
     */
    private function createOrderCoupon(Coupon $coupon, Order $order, $totalPrice): float|int|null
    {
        if ($coupon->qty <= 0) {
            return 0;
        }

        $couponPrice = $coupon->type === 'percent' ? ($totalPrice / 100) * $coupon->price : $coupon->price;

        $order->coupon()->updateOrCreate([
            'user_id' => $order->user_id,
            'name'    => $coupon->name,
        ], [
            'price'   => $couponPrice,
        ]);

        $coupon->decrement('qty');

        return $couponPrice;
    }

    /**
     * @param int|null $orderId
     * @param int $deliveryman
     * @return array
     */
    public function updateDeliveryMan(?int $orderId, int $deliveryman): array
    {
        try {
            /** @var Order $order */
            $order = Order::find($orderId);

            if (!$order) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_404,
                    'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
                ];
            }

            if ($order->delivery_type != Order::DELIVERY) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_502,
                    'message' => __('errors.' . ResponseError::ORDER_POINT, locale: $this->language)
                ];
            }

            /** @var User $user */
            $user = User::with('deliveryManSetting')->find($deliveryman);

            if (!$user || !$user->hasRole('deliveryman')) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_211,
                    'message' => __('errors.' . ResponseError::ERROR_211, locale: $this->language)
                ];
            }

            $order->update([
                'deliveryman_id' => $user->id,
            ]);

            try {
                $actor = auth('sanctum')->user();
                (new OrderActivityLogService)->logDeliverymanAssigned($order, $actor, $user);
            } catch (Throwable) {
                // Audit logging must never affect order flow.
            }

            $this->sendNotification(
                $order,
                is_array($user->firebase_token) ? $user->firebase_token : [$user->firebase_token],
                __('errors.' . ResponseError::NEW_ORDER, ['id' => $order->id], $user->lang ?? $this->language),
                $order->id,
                (new NotificationHelper)->deliveryManOrder($order, PushNotification::NEW_ORDER),
                [$user->id]
            );

            return [
                'status'    => true,
                'message'   => ResponseError::NO_ERROR,
                'data'      => $order,
                'user'      => $user
            ];
        } catch (Throwable $e) {
            $this->error($e);
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_501,
                'message' => __('errors.' . ResponseError::ERROR_501, locale: $this->language)
            ];
        }
    }

    /**
     * @param int|null $id
     * @return array
     */
    public function attachDeliveryMan(?int $id): array
    {
        try {
            /** @var Order $order */
            $order = Order::find($id);

            if ($order->delivery_type != Order::DELIVERY) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_502,
                    'message' => __('errors.' . ResponseError::ORDER_POINT, locale: $this->language)
                ];
            }

            if (!empty($order->deliveryman)) {
                return [
                    'status'    => false,
                    'code'      => ResponseError::ERROR_210,
                    'message'   => __('errors.' . ResponseError::ERROR_210, locale: $this->language)
                ];
            }

            $order->update([
                'deliveryman_id' => auth('sanctum')->id(),
            ]);

            return ['status' => true, 'message' => ResponseError::NO_ERROR, 'data' => $order];
        } catch (Throwable) {
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_502,
                'message' => __('errors.' . ResponseError::ERROR_502, locale: $this->language)
            ];
        }
    }

    /**
     * @param array|null $ids
     * @param int|null $shopId
     * @return array
     */
    public function destroy(?array $ids = [], ?int $shopId = null): array
    {
        $errors = [];

        $orders = Order::with([
            'coupon',
            'orderDetails.stock.product'
        ])
            ->when($shopId, fn($q) => $q->where('shop_id', $shopId))
            ->find(is_array($ids) ? $ids : []);

        foreach ($orders as $order) {

            try {
                DB::transaction(function () use ($order) {

                    /** @var Order $order */
                    foreach ($order->orderDetails as $orderDetail) {

                        OrderHelper::updateStatCount(
                            $orderDetail->stock,
                            $orderDetail?->quantity,
                            false
                        );

                        $orderDetail->delete();
                    }

                    DB::table('push_notifications')
                        ->where('model_type', Order::class)
                        ->where('model_id', $order->id)
                        ->delete();

                    $order->user->update([
                        'o_count' => $order->user->o_count - 1,
                        'o_sum'   => $order->user->o_sum - $order->total_price,
                    ]);

                    $order->pointHistories()->delete();
                    $order->delete();

                });
            } catch (Throwable $e) {
                $errors[] = $order->id;

                $this->error($e);
            }

        }

        return $errors;
    }

    /**
     * @param int $id
     * @param int|null $userId
     * @return array
     */
    public function setCurrent(int $id, ?int $userId = null): array
    {
        $errors = [];

        $orders = Order::when($userId, fn($q) => $q->where('deliveryman_id', $userId))
            ->where('current', 1)

            ->orWhere('id', $id)
            ->get();

        $getOrder = new Order;

        foreach ($orders as $order) {

            try {

                if ($order->id === $id) {

                    $order->update([
                        'current' => true,
                    ]);

                    $getOrder = $order;

                    continue;

                }

                $order->update([
                    'current' => false,
                ]);

            } catch (Throwable $e) {
                $errors[] = $order->id;

                $this->error($e);
            }

        }

        return count($errors) === 0 ? [
            'status' => true,
            'code' => ResponseError::NO_ERROR,
            'data' => $getOrder
        ] : [
            'status'  => false,
            'code'    => ResponseError::ERROR_400,
            'message' => __(
                'errors.' . ResponseError::CANT_UPDATE_ORDERS,
                [
                    'ids' => implode(', #', $errors)
                ],
                $this->language
            )
        ];
    }

    /**
     * @param int $orderId
     * @param array $data
     * @return Order
     * @throws Exception
     */
    public function trackingUpdate(int $orderId, array $data): Order
    {
        $order = Order::find($orderId);

        if (!$order) {
            throw new Exception(__('errors.' . ResponseError::ORDER_NOT_FOUND, locale: $this->language));
        }

        $order->update($data);

        try {
            $actor = auth('sanctum')->user();
            (new OrderActivityLogService)->logTrackingUpdate($order, $actor, $data);
        } catch (Throwable) {
            // Audit logging must never affect order flow.
        }

        return $order;
    }

    /**
     * Update only the alternative phone number on an order.
     *
     * @param int $orderId
     * @param string|null $phoneAlt
     * @return Order
     * @throws Exception
     */
    public function phoneAltUpdate(int $orderId, ?string $phoneAlt): Order
    {
        $order = Order::find($orderId);

        if (!$order) {
            throw new Exception(__('errors.' . ResponseError::ORDER_NOT_FOUND, locale: $this->language));
        }

        $order->update(['phone_alt' => $phoneAlt]);

        return $order;
    }
}
