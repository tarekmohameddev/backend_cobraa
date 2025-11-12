<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Http\Controllers\API\v1\Dashboard\Admin\EasyOrders;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\EasyOrders\Entities\EasyOrdersStore;
use Modules\EasyOrders\Http\Requests\StoreRequest;
use Modules\EasyOrders\Repositories\EasyOrdersStoreRepository;

class StoreController extends Controller
{
	public function __construct(private readonly EasyOrdersStoreRepository $repository)
	{
	}

	public function index(\Illuminate\Http\Request $request): JsonResponse
	{
		$stores = $this->repository->paginate($request->all());
		return response()->json($stores);
	}

	public function store(StoreRequest $request): JsonResponse
	{
		$data = $request->validated();

		$data['webhook_secret'] = $data['webhook_secret'] ?? Str::random(40);
		$store = EasyOrdersStore::query()->create([
			'name' => $data['name'],
			'external_store_id' => $data['external_store_id'] ?? null,
			'status' => $data['status'] ?? 'active',
			'api_key' => $data['api_key'] ?? null,
			'webhook_secret' => $data['webhook_secret'],
			'settings' => [],
		]);

		return response()->json($store, 201);
	}

	public function update(StoreRequest $request, int $id): JsonResponse
	{
		$store = EasyOrdersStore::query()->findOrFail($id);

		$data = $request->validated();

		$store->fill($data)->save();
		return response()->json($store);
	}

	public function rotateSecret(int $id): JsonResponse
	{
		$store = EasyOrdersStore::query()->findOrFail($id);
		$store->webhook_secret = Str::random(40);
		$store->save();

		return response()->json(['webhook_secret' => $store->webhook_secret]);
	}

	public function testConnection(int $id): JsonResponse
	{
		$store = EasyOrdersStore::query()->findOrFail($id);
		return response()->json([
			'status' => 'ok',
			'store_id' => $store->id,
			'external_store_id' => $store->external_store_id,
			'has_api_key' => (bool) $store->api_key,
		]);
	}
}


