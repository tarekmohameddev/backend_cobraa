<?php

declare(strict_types=1);

namespace Modules\Stox\Http\Controllers\Dashboard\Admin;

use App\Helpers\ResponseError;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Response;
use Modules\Stox\Http\Resources\StoxOperationLogResource;
use Modules\Stox\Repositories\StoxOperationLogRepository;

class StoxOperationLogController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StoxOperationLogRepository $repository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $logs = $this->repository->paginate($request->all());

        return $this->successResponse(
            __('errors.' . ResponseError::NO_ERROR),
            StoxOperationLogResource::collection($logs)
        );
    }

    public function export(Request $request)
    {
        $logs = $this->repository->all($request->all());

        $callback = static function () use ($logs): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'ID',
                'Operation',
                'Order ID',
                'Account ID',
                'HTTP Status',
                'Trigger Type',
                'Error',
                'Created At',
            ]);

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->id,
                    $log->operation_type,
                    $log->order_id,
                    $log->stox_account_id,
                    $log->http_status,
                    $log->trigger_type,
                    $log->error_message,
                    $log->created_at,
                ]);
            }

            fclose($handle);
        };

        return Response::streamDownload($callback, 'stox-operation-logs.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}

