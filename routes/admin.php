<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DelhiverySettingController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\OrderShipmentController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\BannerController;

// Admin Authentication Routes
Route::prefix('cpanel')->group(function () {
    Route::post('login', [AdminAuthController::class, 'login']);
    
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AdminAuthController::class, 'logout']);
        Route::get('profile', [AdminAuthController::class, 'profile']);
        Route::put('profile', [AdminAuthController::class, 'updateProfile']);
        Route::post('refresh-token', [AdminAuthController::class, 'refreshToken']);
        
        // Categories Management
        Route::apiResource('categories', CategoryController::class);
        
        // Products Management
        Route::apiResource('products', ProductController::class);

        // Banners Management
        Route::apiResource('banners', BannerController::class)->except(['update']);

        // Delhivery Settings
        Route::get('delhivery-settings', [DelhiverySettingController::class, 'show']);
        Route::put('delhivery-settings', [DelhiverySettingController::class, 'update']);

        // Orders Management
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{id}', [OrderController::class, 'show']);
        Route::post('orders/{id}/shipment/create', [OrderShipmentController::class, 'create']);
        Route::post('orders/{id}/shipment/sync', [OrderShipmentController::class, 'sync']); //this is used to delivery ststus latest sync from delhivery api
        Route::post('orders/{id}/shipment/cancel', [OrderShipmentController::class, 'cancel']);
        Route::get('orders/{id}/shipment/label', [OrderShipmentController::class, 'label']); //this is used to get the shipment label
    });
});
