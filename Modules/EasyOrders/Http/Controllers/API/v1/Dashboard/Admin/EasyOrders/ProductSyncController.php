<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Http\Controllers\API\v1\Dashboard\Admin\EasyOrders;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
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

		/**
		 * Idempotency / single active sync:
		 * - If any sync is already pending/processing, return it instead of starting a new one.
		 * - This prevents duplicate dispatcher chains on database queues (retry_after) and UI double-clicks.
		 */
		$active = EasyOrdersProductSync::query()
			->whereIn('status', [EasyOrdersProductSync::STATUS_PENDING, EasyOrdersProductSync::STATUS_PROCESSING])
			->orderByDesc('id')
			->first();

		if ($active) {
			return response()->json([
				'message' => 'EasyOrders product sync is already running',
				'sync' => $this->formatSyncStatus($active),
			]);
		}

		// Create sync record to track progress (wrapped in a transaction to reduce race conditions)
		$syncRecord = DB::transaction(function () use ($userId, $page) {
			$activeInsideTx = EasyOrdersProductSync::query()
				->whereIn('status', [EasyOrdersProductSync::STATUS_PENDING, EasyOrdersProductSync::STATUS_PROCESSING])
				->lockForUpdate()
				->orderByDesc('id')
				->first();

			if ($activeInsideTx) {
				return $activeInsideTx;
			}

			return EasyOrdersProductSync::create([
				'user_id' => $userId,
				'status' => EasyOrdersProductSync::STATUS_PENDING,
				'start_page' => $page,
				'metadata' => [
					'queued_products' => 0,
					'dispatch_done' => false,
				],
			]);
		});

		// If transaction returned an already-active sync, just return it
		if ($syncRecord->status !== EasyOrdersProductSync::STATUS_PENDING) {
			return response()->json([
				'message' => 'EasyOrders product sync is already running',
				'sync' => $this->formatSyncStatus($syncRecord),
			]);
		}

		// Dispatch the first page dispatcher job
		SyncProductsJob::dispatch($page, $userId, $syncRecord->id)->onQueue('default');

		return response()->json([
			'message' => 'EasyOrders product sync has been queued and will process in the background',
			'sync' => $this->formatSyncStatus($syncRecord),
		]);
	}

	/**
	 * Get the status of a product sync
	 */
	public function getSyncStatus(Request $request, ?int $syncId = null): JsonResponse
	{
		// If no sync ID provided, get the latest active sync for the user
		if (!$syncId) {
			$syncRecord = EasyOrdersProductSync::query()
				->orderByDesc('id')
				->first();
		} else {
			$syncRecord = EasyOrdersProductSync::find($syncId);
		}

		if (!$syncRecord) {
			return response()->json([
				'message' => 'No active sync found',
			], 404);
		}

		return response()->json($this->formatSyncStatus($syncRecord));
	}

	public function syncOne(string $externalProductId): JsonResponse
	{
		$product = $this->service->syncOne($externalProductId);

		if (!$product) {
			return response()->json(['message' => 'Product not found from EasyOrders'], 404);
		}

		return response()->json($product);
	}

	/**
	 * Format sync status payload consistently for API consumers.
	 *
	 * @return array<string, mixed>
	 */
	private function formatSyncStatus(EasyOrdersProductSync $syncRecord): array
	{
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

		return [
			'id' => $syncRecord->id,
			'status' => $syncRecord->status,
			'start_page' => $syncRecord->start_page,
			'current_page' => $syncRecord->current_page,
			'total_pages' => $syncRecord->total_pages,
			'products_synced' => (int) $syncRecord->products_synced,
			'products_failed' => (int) $syncRecord->products_failed,
			'queued_products' => $queuedProducts,
			'processed_products' => $processedProducts,
			'dispatch_done' => $dispatchDone,
			'progress' => $progress,
			'error_message' => $syncRecord->error_message,
			'started_at' => $syncRecord->started_at?->toIso8601String(),
			'completed_at' => $syncRecord->completed_at?->toIso8601String(),
			'created_at' => $syncRecord->created_at->toIso8601String(),
			'updated_at' => $syncRecord->updated_at->toIso8601String(),
		];
	}
}


