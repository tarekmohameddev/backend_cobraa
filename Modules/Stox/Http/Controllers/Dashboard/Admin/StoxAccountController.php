<?php

declare(strict_types=1);

namespace Modules\Stox\Http\Controllers\Dashboard\Admin;

use App\Helpers\ResponseError;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Stox\Entities\StoxAccount;
use Modules\Stox\Http\Requests\StoxAccountRequest;
use Modules\Stox\Http\Requests\StoxAccountUpdateRequest;
use Modules\Stox\Http\Resources\StoxAccountResource;
use Modules\Stox\Repositories\StoxAccountRepository;
use Modules\Stox\Services\StoxApiService;

class StoxAccountController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly StoxAccountRepository $repository,
        private readonly StoxApiService $apiService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $accounts = $this->repository->paginate($request->all());

        return $this->successResponse(
            __('errors.' . ResponseError::NO_ERROR),
            StoxAccountResource::collection($accounts)
        );
    }

    public function store(StoxAccountRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['base_url'] = $data['base_url'] ?? config('stox.default_base_url');
        $data['status'] = $data['status'] ?? 'active';

        $account = StoxAccount::query()->create($data);

        return $this->successResponse(
            __('errors.' . ResponseError::NO_ERROR),
            StoxAccountResource::make($account)
        );
    }

    public function show(int $stoxAccount): JsonResponse
    {
        $stoxAccount = StoxAccount::query()->findOrFail($stoxAccount);

        return $this->successResponse(
            __('errors.' . ResponseError::NO_ERROR),
            StoxAccountResource::make($stoxAccount)
        );
    }

    public function update(StoxAccountUpdateRequest $request, int $stoxAccount): JsonResponse
    {
        $stoxAccount = StoxAccount::query()->findOrFail($stoxAccount);

        $data = $request->validated();
        $data['base_url'] = $data['base_url'] ?? $stoxAccount->base_url;
        $data['status'] = $data['status'] ?? $stoxAccount->status;

        if (empty($data['bearer_token'])) {
            unset($data['bearer_token']);
        }

        $stoxAccount->fill($data)->save();

        return $this->successResponse(
            __('errors.' . ResponseError::NO_ERROR),
            StoxAccountResource::make($stoxAccount)
        );
    }

    public function destroy(int $stoxAccount): JsonResponse
    {
        $stoxAccountModel = StoxAccount::query()->findOrFail($stoxAccount);
        $stoxAccountModel->delete();

        return $this->successResponse(__('errors.' . ResponseError::NO_ERROR));
    }

    public function testConnection(int $stoxAccount): JsonResponse
    {
        $stoxAccountModel = StoxAccount::query()->findOrFail($stoxAccount);

        $result = $this->apiService->testConnection($stoxAccountModel);

        if (!$result['success']) {
            return $this->errorResponse(
                ResponseError::ERROR_400,
                $result['error'] ?? 'Unable to connect to Stox.',
                400
            );
        }

        return $this->successResponse(
            __('errors.' . ResponseError::NO_ERROR),
            $result
        );
    }
}

