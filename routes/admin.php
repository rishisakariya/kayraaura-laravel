<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\CategoryController;

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
    });
});
