<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\OrderEnhancements\Http\Controllers\Dashboard\Admin\OrderActivityLogController;
use Modules\OrderEnhancements\Http\Controllers\Dashboard\Admin\OrderUpdateController;

Route::prefix('v1')
    ->middleware(['auth:sanctum'])
    ->group(function (): void {
        Route::prefix('dashboard/admin/orders/{order}/updates')->group(function (): void {
            Route::get('/', [OrderUpdateController::class, 'index']);
            Route::post('/', [OrderUpdateController::class, 'store']);
        });

        Route::prefix('dashboard/admin/orders/{order}/activity-logs')->group(function (): void {
            Route::get('/', [OrderActivityLogController::class, 'index']);
        });
    });