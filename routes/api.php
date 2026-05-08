<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Frontend\FrontendAuthController;

Route::get('/test', function () {
    return response()->json([
        'status' => true,
        'message' => 'Frontend API working 🚀'
    ]);
});

// Frontend Authentication Routes
Route::prefix('auth')->group(function () {
    Route::post('register', [FrontendAuthController::class, 'register']);
    Route::post('login', [FrontendAuthController::class, 'login']);
    Route::post('logout', [FrontendAuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('profile', [FrontendAuthController::class, 'profile'])->middleware('auth:sanctum');
    Route::post('forgot-password', [FrontendAuthController::class, 'forgotPassword']);
    Route::post('reset-password', [FrontendAuthController::class, 'resetPassword']);
    Route::post('verify-email', [FrontendAuthController::class, 'verifyEmail']);
});

// Frontend Product Routes (will be added later)
// Frontend Category Routes (will be added later)
// Frontend Cart Routes (will be added later)
// Frontend Order Routes (will be added later)