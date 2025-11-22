<?php

use Illuminate\Support\Facades\Route;
use Modules\UserManagement\Http\Controllers\UserAddressController;
use Modules\UserManagement\Http\Controllers\LocationController;
use Modules\UserManagement\Http\Controllers\OrderLocationController;

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

Route::group(['prefix' => 'v1/dashboard/admin/user-management', 'middleware' => ['auth:sanctum']], function () {
    
    // User + Address Creation
    Route::post('/users', [UserAddressController::class, 'store']);

    // Create address for existing user
    Route::post('/user-addresses', [UserAddressController::class, 'storeExistingUserAddress']);

    // Location Management
    Route::get('/areas', [LocationController::class, 'getAreas']);
    Route::get('/areas/{id}/city', [LocationController::class, 'getCityByArea']);
    Route::get('/cities/{id}/areas', [LocationController::class, 'getAreasByCity']);

    // Order Location & Shipping Update
    Route::get('/orders/{id}', [OrderLocationController::class, 'show']);
    Route::put('/orders/{id}/location', [OrderLocationController::class, 'updateLocation']);

});
