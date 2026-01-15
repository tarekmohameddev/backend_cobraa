<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Http\Controllers\API\v1\Dashboard\Admin\EasyOrders;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\EasyOrders\Entities\EasyOrdersTempOrder;
use Modules\EasyOrders\Jobs\ImportTempOrderJob;
use Modules\EasyOrders\Jobs\ValidateTempOrderJob;
use Modules\EasyOrders\Http\Requests\ApproveTempOrdersRequest;
use Modules\EasyOrders\Http\Requests\BulkTempOrdersRequest;
use Modules\EasyOrders\Repositories\EasyOrdersTempOrderRepository;

class TempOrderController extends Controller
{
	public function __construct(private readonly EasyOrdersTempOrderRepository $repository)
	{
	}

	public function index(\Illuminate\Http\Request $request): JsonResponse
	{
		$filters = $request->all();
		$items = $this->repository->paginate($filters);
		$response = $items->toArray();
		$response['stats'] = $this->repository->stats($filters);
		return response()->json($response);
	}

	public function show(int $id): JsonResponse
	{
		$item = EasyOrdersTempOrder::query()->with('store')->findOrFail($id);
		return response()->json($item);
	}

	public function approve(int $id): JsonResponse
	{
		$order = EasyOrdersTempOrder::query()->findOrFail($id);
		if (!in_array($order->status, ['validated', 'approved'])) {
			$order->status = 'approved';
			$order->save();
		}
		ImportTempOrderJob::dispatch($order->id)->onQueue('default');
		return response()->json(['message' => 'queued']);
	}

	public function approveBulk(ApproveTempOrdersRequest $request): JsonResponse
	{
		$ids = $request->validated()['ids'];
		$orders = EasyOrdersTempOrder::query()->whereIn('id', $ids)->get();
		foreach ($orders as $order) {
			if (!in_array($order->status, ['validated', 'approved'])) {
				$order->status = 'approved';
				$order->save();
			}
			ImportTempOrderJob::dispatch($order->id)->onQueue('default');
		}
		return response()->json(['message' => 'queued', 'count' => $orders->count()]);
	}

	/**
	 * Bulk actions for temp orders.
	 *
	 * Supported actions:
	 * - approve_and_import: mark approved (if needed) then queue import
	 * - validate: queue validation
	 * - import: queue import only when status is validated/approved
	 * - delete: delete (skips imported unless force=true)
	 */
	public function bulk(BulkTempOrdersRequest $request): JsonResponse
	{
		$data = $request->validated();
		$action = $data['action'];
		$ids = $data['ids'];
		$force = (bool) ($data['force'] ?? false);

		$orders = EasyOrdersTempOrder::query()->whereIn('id', $ids)->get();

		$processed = 0;
		$skipped = 0;
		$skippedIds = [];

		foreach ($orders as $order) {
			if ($action === 'approve_and_import') {
				if (!in_array($order->status, ['validated', 'approved'], true)) {
					$order->status = 'approved';
					$order->save();
				}
				ImportTempOrderJob::dispatch($order->id)->onQueue('default');
				$processed++;
				continue;
			}

			if ($action === 'validate') {
				ValidateTempOrderJob::dispatch($order->id)->onQueue('default');
				$processed++;
				continue;
			}

			if ($action === 'import') {
				if (!in_array($order->status, ['validated', 'approved'], true)) {
					$skipped++;
					$skippedIds[] = $order->id;
					continue;
				}
				ImportTempOrderJob::dispatch($order->id)->onQueue('default');
				$processed++;
				continue;
			}

			if ($action === 'delete') {
				if (!$force && $order->status === 'imported') {
					$skipped++;
					$skippedIds[] = $order->id;
					continue;
				}
				$order->delete();
				$processed++;
				continue;
			}
		}

		return response()->json([
			'message' => 'ok',
			'action' => $action,
			'requested_count' => count($ids),
			'matched_count' => $orders->count(),
			'processed' => $processed,
			'skipped' => $skipped,
			'skipped_ids' => $skippedIds,
		]);
	}
}


