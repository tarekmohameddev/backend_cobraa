<?php

use Illuminate\Support\Facades\Route;
use Modules\OrderLocking\Http\Controllers\OrderLockController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Routes for order locking heartbeat and unlock operations
|
*/

Route::prefix('v1/dashboard/admin/orders')
    ->middleware(['sanctum.check', 'role:seller|manager'])
    ->group(function () {
        Route::post('{id}/heartbeat', [OrderLockController::class, 'heartbeat']);
        Route::delete('{id}/lock', [OrderLockController::class, 'unlock']);
    });
