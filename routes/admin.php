<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AdminAuthController;

// Admin Authentication Routes
Route::prefix('cpanel')->group(function () {
    Route::post('login', [AdminAuthController::class, 'login']);
    
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AdminAuthController::class, 'logout']);
        Route::get('profile', [AdminAuthController::class, 'profile']);
        Route::put('profile', [AdminAuthController::class, 'updateProfile']);
        Route::post('refresh-token', [AdminAuthController::class, 'refreshToken']);
    });
});
