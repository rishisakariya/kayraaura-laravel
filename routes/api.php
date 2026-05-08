<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\FrontendAuthController;
use App\Http\Controllers\API\CategoryController;
use App\Http\Controllers\API\ProductController;

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

// Frontend Category Routes (Public)
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{slug}', [CategoryController::class, 'show']);

// Frontend Product Routes (Public)
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{slug}', [ProductController::class, 'show']);
Route::get('/products/featured', [ProductController::class, 'featured']);
Route::get('/products/category/{category_id}', [ProductController::class, 'byCategory']);
Route::get('/products/search', [ProductController::class, 'search']);

// Frontend Cart Routes (will be added later)
// Frontend Order Routes (will be added later)