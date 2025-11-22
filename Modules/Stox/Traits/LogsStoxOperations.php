<?php

declare(strict_types=1);

namespace Modules\Stox\Traits;

use Modules\Stox\Entities\StoxAccount;
use Modules\Stox\Entities\StoxOperationLog;
use Modules\Stox\Entities\StoxOrder;
use Modules\Stox\Services\StoxOperationLogger;

trait LogsStoxOperations
{
    protected function logStoxOperation(
        string $operationType,
        ?StoxAccount $account,
        ?StoxOrder $stoxOrder,
        ?int $orderId,
        ?\App\Models\User $user,
        array $requestData = [],
        ?array $responseData = null,
        ?int $httpStatus = null,
        ?string $triggerType = 'manual',
        ?string $errorMessage = null,
        ?string $stackTrace = null,
        ?int $executionTimeMs = null
    ): StoxOperationLog {
        /** @var StoxOperationLogger $logger */
        $logger = app(StoxOperationLogger::class);

        return $logger->log(
            $operationType,
            $account,
            $stoxOrder,
            $orderId,
            $user,
            $requestData,
            $responseData,
            $httpStatus,
            $triggerType,
            $errorMessage,
            $stackTrace,
            $executionTimeMs
        );
    }
}

