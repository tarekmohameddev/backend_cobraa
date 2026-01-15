<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Http\Controllers\API\v1\Dashboard\Admin\EasyOrders;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\EasyOrders\Entities\EasyOrdersProductSync;
use Modules\EasyOrders\Jobs\SyncProductsJob;
use Modules\EasyOrders\Services\ProductSyncService;

class ProductSyncController extends Controller
{
	public function __construct(
		private readonly ProductSyncService $service,
	) {}

	public function syncAll(Request $request): JsonResponse
	{
		$page = (int) $request->input('page', 1);
		$userId = auth('sanctum')->id();

		// Create sync record to track progress
		$syncRecord = EasyOrdersProductSync::create([
			'user_id' => $userId,
			'status' => EasyOrdersProductSync::STATUS_PENDING,
			'start_page' => $page,
		]);

		// Dispatch the sync job to run asynchronously
		SyncProductsJob::dispatch($page, $userId, $syncRecord->id);

		return response()->json([
			'message' => 'EasyOrders product sync has been queued and will process in the background',
			'sync_id' => $syncRecord->id,
			'page'    => $page,
			'status'  => 'queued',
		]);
	}

	/**
	 * Get the status of a product sync
	 */
	public function getSyncStatus(Request $request, ?int $syncId = null): JsonResponse
	{
		$userId = auth('sanctum')->id();

		// If no sync ID provided, get the latest active sync for the user
		if (!$syncId) {
			$syncRecord = EasyOrdersProductSync::getLatestActive($userId);
		} else {
			$syncRecord = EasyOrdersProductSync::find($syncId);
			
			// Verify the sync belongs to the user (unless admin)
			if ($syncRecord && $syncRecord->user_id !== $userId) {
				return response()->json([
					'message' => 'Sync record not found or access denied',
				], 404);
			}
		}

		if (!$syncRecord) {
			return response()->json([
				'message' => 'No active sync found',
			], 404);
		}

		$metadata = $syncRecord->metadata ?? [];
		$queuedProducts = (int) data_get($metadata, 'queued_products', 0);
		$dispatchDone = (bool) data_get($metadata, 'dispatch_done', false);
		$processedProducts = (int) $syncRecord->products_synced + (int) $syncRecord->products_failed;

		// Progress is based on products when available (more accurate than page-based)
		$progress = null;
		if ($queuedProducts > 0) {
			$progress = min(100, (int) (($processedProducts / $queuedProducts) * 100));
		} elseif ($syncRecord->total_pages && $syncRecord->current_page) {
			$progress = min(100, (int) (($syncRecord->current_page / $syncRecord->total_pages) * 100));
		}

		return response()->json([
			'id' => $syncRecord->id,
			'status' => $syncRecord->status,
			'start_page' => $syncRecord->start_page,
			'current_page' => $syncRecord->current_page,
			'total_pages' => $syncRecord->total_pages,
			'products_synced' => $syncRecord->products_synced,
			'products_failed' => $syncRecord->products_failed,
			'queued_products' => $queuedProducts,
			'processed_products' => $processedProducts,
			'dispatch_done' => $dispatchDone,
			'progress' => $progress,
			'error_message' => $syncRecord->error_message,
			'started_at' => $syncRecord->started_at?->toIso8601String(),
			'completed_at' => $syncRecord->completed_at?->toIso8601String(),
			'created_at' => $syncRecord->created_at->toIso8601String(),
			'updated_at' => $syncRecord->updated_at->toIso8601String(),
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


