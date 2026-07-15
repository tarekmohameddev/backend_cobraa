<?php

declare(strict_types=1);

namespace Modules\OrderEnhancements\Http\Controllers\Dashboard\Admin;

use App\Helpers\ResponseError;
use App\Http\Resources\StockResource;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Modules\OrderEnhancements\Http\Requests\LookupStockBySkuRequest;
use Modules\OrderEnhancements\Http\Requests\StockBarcodesRequest;
use Modules\OrderEnhancements\Http\Resources\BarcodeResource;
use Modules\OrderEnhancements\Services\StockBarcodeService;

class StockBarcodeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly StockBarcodeService $service
    ) {}

    public function lookupBySku(LookupStockBySkuRequest $request): JsonResponse
    {
        $stock = $this->service->findBySku(
            sku: $request->input('sku'),
            shopId: $request->input('shop_id') ? (int) $request->input('shop_id') : null,
        );

        if (!$stock) {
            return $this->onErrorResponse(['code' => ResponseError::ERROR_404]);
        }

        if ((int) $stock->quantity < 1) {
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_430,
                'message' => 'There is no stock for this item',
            ]);
        }

        return $this->successResponse(
            __('errors.' . ResponseError::NO_ERROR),
            StockResource::make($stock)
        );
    }

    public function barcodes(StockBarcodesRequest $request): JsonResponse|AnonymousResourceCollection
    {
        $stocks = $this->service->getBarcodesForProducts(
            productIds: $request->input('product_ids'),
            shopId: $request->input('shop_id') ? (int) $request->input('shop_id') : null,
        );

        return $this->successResponse(
            __('errors.' . ResponseError::NO_ERROR),
            BarcodeResource::collection($stocks)
        );
    }
}
