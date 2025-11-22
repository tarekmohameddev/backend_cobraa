<?php

declare(strict_types=1);

namespace Modules\Stox\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Modules\Stox\Entities\StoxAccount;
use Modules\Stox\Entities\StoxOperationLog;
use Modules\Stox\Entities\StoxOrder;

class StoxOperationLogger
{
    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed>|null $responseData
     */
    public function log(
        string $operationType,
        ?StoxAccount $account,
        ?StoxOrder $stoxOrder,
        ?int $orderId,
        ?User $user,
        array $requestData = [],
        ?array $responseData = null,
        ?int $httpStatus = null,
        ?string $triggerType = 'manual',
        ?string $errorMessage = null,
        ?string $stackTrace = null,
        ?int $executionTimeMs = null
    ): StoxOperationLog {
        $request = request();

        return StoxOperationLog::query()->create([
            'stox_account_id' => $account?->id,
            'stox_order_id' => $stoxOrder?->id,
            'order_id' => $orderId,
            'user_id' => $user?->id,
            'operation_type' => $operationType,
            'trigger_type' => $triggerType,
            'http_status' => $httpStatus,
            'execution_time_ms' => $executionTimeMs,
            'request_data' => $requestData,
            'response_data' => $responseData,
            'error_message' => $errorMessage,
            'stack_trace' => $stackTrace,
            'ip_address' => $this->getIpAddress($request),
            'user_agent' => $this->getUserAgent($request),
        ]);
    }

    private function getIpAddress(?Request $request): ?string
    {
        return $request?->ip();
    }

    private function getUserAgent(?Request $request): ?string
    {
        return $request?->userAgent();
    }
}

