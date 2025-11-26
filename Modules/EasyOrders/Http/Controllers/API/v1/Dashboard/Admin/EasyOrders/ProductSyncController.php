<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Http\Controllers\API\v1\Dashboard\Admin\EasyOrders;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\EasyOrders\Services\ProductSyncService;

class ProductSyncController extends Controller
{
	public function __construct(
		private readonly ProductSyncService $service,
	) {}

	public function syncAll(Request $request): JsonResponse
	{
		$page = (int) $request->input('page', 1);

		$this->service->syncAll($page);

		return response()->json([
			'message' => 'EasyOrders product sync started',
			'page'    => $page,
		]);
	}

	public function syncOne(string $externalProductId): JsonResponse
	{
		$product = $this->service->syncOne($externalProductId);

		if (!$product) {
			return response()->json(['message' => 'Product not found from EasyOrders'], 404);
		}

		return response()->json($product);
	}
}


