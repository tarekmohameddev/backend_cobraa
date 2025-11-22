<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Stox\Http\Controllers\Dashboard\Admin\StoxAccountController;
use Modules\Stox\Http\Controllers\Dashboard\Admin\StoxOperationLogController;
use Modules\Stox\Http\Controllers\Dashboard\Admin\StoxOrderController;

Route::prefix('v1')
    ->middleware(['auth:sanctum'])
    ->group(function (): void {
        Route::prefix('dashboard/admin/stox')->group(function (): void {
            Route::get('accounts', [StoxAccountController::class, 'index']);
            Route::post('accounts', [StoxAccountController::class, 'store']);
            Route::get('accounts/{stoxAccount}', [StoxAccountController::class, 'show']);
            Route::put('accounts/{stoxAccount}', [StoxAccountController::class, 'update']);
            Route::delete('accounts/{stoxAccount}', [StoxAccountController::class, 'destroy']);
            Route::post('accounts/{stoxAccount}/test-connection', [StoxAccountController::class, 'testConnection']);

            Route::get('orders', [StoxOrderController::class, 'index']);
            Route::post('orders/{order}/export', [StoxOrderController::class, 'export']);
            Route::get('orders/{stoxOrder}', [StoxOrderController::class, 'show']);
            Route::post('orders/{stoxOrder}/retry', [StoxOrderController::class, 'retry']);

            Route::get('operation-logs', [StoxOperationLogController::class, 'index']);
            Route::get('operation-logs/export', [StoxOperationLogController::class, 'export']);
        });
    });

