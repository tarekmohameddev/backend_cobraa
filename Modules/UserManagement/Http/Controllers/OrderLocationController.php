<?php
declare(strict_types=1);

namespace Modules\UserManagement\Http\Controllers;

use App\Helpers\ResponseError;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\City;
use App\Models\DeliveryPrice;
use App\Models\Order;
use App\Models\UserAddress;
use App\Services\OrderService\OrderService;
use Illuminate\Http\JsonResponse;
use Modules\UserManagement\Http\Requests\UpdateOrderLocationRequest;
use Throwable;

class OrderLocationController extends Controller
{
    public function __construct(private OrderService $orderService)
    {
    }

    /**
     * Get order details.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $order = Order::with(['user', 'shop', 'orderDetails', 'transaction', 'myAddress', 'deliveryPrice'])->find($id);

        if (!$order) {
            return $this->errorResponse(ResponseError::ERROR_404, 'Order not found');
        }

        return $this->successResponse(
            __('errors.' . ResponseError::NO_ERROR, locale: request('lang')),
            OrderResource::make($order)
        );
    }

    /**
     * Update the city and area of an order (and recalculate shipping).
     *
     * @param int $id
     * @param UpdateOrderLocationRequest $request
     * @return JsonResponse
     */
    public function updateLocation(int $id, UpdateOrderLocationRequest $request): JsonResponse
    {
        /** @var Order $order */
        $order = Order::find($id);

        if (!$order) {
            return $this->errorResponse(ResponseError::ERROR_404, 'Order not found');
        }

        $validated = $request->validated();
        $cityId = $validated['city_id'];
        $areaId = $validated['area_id'];

        // Find City to get country/region info
        $city = City::find($cityId);
        if (!$city) {
            return $this->errorResponse(ResponseError::ERROR_404, 'City not found');
        }

        // Find matching DeliveryPrice for the Shop and new Location
        $deliveryPrice = DeliveryPrice::where('shop_id', $order->shop_id)
            ->where('city_id', $cityId)
            ->where('area_id', $areaId)
            ->first();

        if (!$deliveryPrice) {
             $deliveryPrice = DeliveryPrice::where('shop_id', $order->shop_id)
                ->where(function($q) use ($cityId, $areaId) {
                    $q->where('area_id', $areaId)
                      ->orWhere(function($q2) use ($cityId) {
                          $q2->where('city_id', $cityId)->whereNull('area_id');
                      });
                })
                ->orderBy('area_id', 'desc')
                ->first();
        }

        if (!$deliveryPrice) {
            return $this->errorResponse(ResponseError::ERROR_400, 'Delivery not available for this location');
        }

        // Address Handling
        $addressData = [
            'user_id'    => $order->user_id,
            'country_id' => $city->country_id,
            'region_id'  => $city->region_id,
            'city_id'    => $cityId,
            'area_id'    => $areaId,
            'address'    => ['address' => $validated['address'] ?? ''],
            'location'   => $validated['location'] ?? [],
            'active'     => true,
            'firstname'  => $order->username ?? '',
            'lastname'   => '',
            'phone'      => $order->phone ?? '',
        ];

        $newAddressId = $order->address_id;

        if (!$order->address_id) {
            // 2. No address_id -> Create new
            $newAddress = UserAddress::create($addressData);
            $newAddressId = $newAddress->id;
        } else {
            // 3. Has address_id
            $userAddress = UserAddress::find($order->address_id);

            if (!$userAddress) {
                 // Should not happen but handle safety
                 $newAddress = UserAddress::create($addressData);
                 $newAddressId = $newAddress->id;
            } else {
                if (!$userAddress->city_id || !$userAddress->area_id) {
                    // Update existing
                    $userAddress->update($addressData);
                    $newAddressId = $userAddress->id;
                } else {
                    // Create new because both exist
                    $newAddress = UserAddress::create($addressData);
                    $newAddressId = $newAddress->id;
                }
            }
        }

        // Prepare data for update
        $currentAddress = $order->address ?? [];
        $orderAddressJson = array_merge($currentAddress, [
            'address' => $validated['address'] ?? ($currentAddress['address'] ?? ''),
            'city_id' => $cityId,
            'area_id' => $areaId,
        ]);
        
        if (isset($validated['location'])) {
             $orderAddressJson['location'] = $validated['location'];
        }

        $updateData = [
            'delivery_price_id' => $deliveryPrice->id,
            'address_id'        => $newAddressId,
            'address'           => $orderAddressJson,
            'location'          => $validated['location'] ?? $order->location,
            'delivery_type'     => Order::DELIVERY, // Ensure delivery type is set for fee calculation
        ];

        // We must pass existing products to avoid "empty order" error in OrderDetailService.
        $updateData['products'] = $order->orderDetails->map(function($detail) {
            return [
                'stock_id'      => $detail->stock_id,
                'quantity'      => $detail->quantity,
                'bonus'         => (bool)$detail->bonus,
                'price'         => $detail->total_price, 
                'replace_stock_id' => $detail->replace_stock_id,
                'replace_quantity' => $detail->replace_quantity,
                'replace_note'     => $detail->replace_note,
            ];
        })->toArray();

        $result = $this->orderService->update($order->id, $updateData);

        if (!data_get($result, 'status')) {
            return $this->errorResponse(data_get($result, 'code', ResponseError::ERROR_502), data_get($result, 'message', 'Update failed'));
        }

        return $this->successResponse(
            __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_UPDATED, locale: request('lang')),
            OrderResource::make(data_get($result, 'data'))
        );
    }

    /**
     * Success Response
     */
    private function successResponse($message, $data): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $data
        ]);
    }

    /**
     * Error Response
     */
    private function errorResponse($code, $message): JsonResponse
    {
        return response()->json([
            'status' => false,
            'code' => $code,
            'message' => $message
        ], 400);
    }
}

