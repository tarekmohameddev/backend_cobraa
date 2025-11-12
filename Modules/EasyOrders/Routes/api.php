<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\EasyOrders\Http\Controllers\API\v1\Rest\Integrations\EasyOrdersWebhookController;
use Modules\EasyOrders\Http\Controllers\API\v1\Dashboard\Admin\EasyOrders\StoreController;
use Modules\EasyOrders\Http\Controllers\API\v1\Dashboard\Admin\EasyOrders\TempOrderController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::prefix('v1')->group(function () {
	// Public webhook endpoint (throttled at app level if needed)
	Route::post('integrations/easyorders/webhook', [EasyOrdersWebhookController::class, 'store'])
		->middleware('throttle:120,1');

	// Admin endpoints (attach your auth/middleware group externally or here if needed)
	Route::prefix('dashboard/admin/easyorders')
		->middleware(['auth:sanctum'])
		->group(function () {
		// Stores
		Route::get('stores', [StoreController::class, 'index']);
		Route::post('stores', [StoreController::class, 'store']);
		Route::put('stores/{id}', [StoreController::class, 'update']);
		Route::post('stores/{id}/rotate-secret', [StoreController::class, 'rotateSecret']);
		Route::post('stores/{id}/test-connection', [StoreController::class, 'testConnection']);

		// Temp Orders
		Route::get('temp-orders', [TempOrderController::class, 'index']);
		Route::get('temp-orders/{id}', [TempOrderController::class, 'show']);
		Route::post('temp-orders/{id}/approve', [TempOrderController::class, 'approve']);
		Route::post('temp-orders/approve', [TempOrderController::class, 'approveBulk']);
	});
});