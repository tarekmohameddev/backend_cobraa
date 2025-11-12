<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Http\Controllers\API\v1\Dashboard\Admin\EasyOrders;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\EasyOrders\Entities\EasyOrdersTempOrder;
use Modules\EasyOrders\Jobs\ImportTempOrderJob;
use Modules\EasyOrders\Http\Requests\ApproveTempOrdersRequest;
use Modules\EasyOrders\Repositories\EasyOrdersTempOrderRepository;

class TempOrderController extends Controller
{
	public function __construct(private readonly EasyOrdersTempOrderRepository $repository)
	{
	}

	public function index(\Illuminate\Http\Request $request): JsonResponse
	{
		$items = $this->repository->paginate($request->all());
		return response()->json($items);
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
}


