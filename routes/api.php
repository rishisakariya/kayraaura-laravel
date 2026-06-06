<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\FrontendAuthController;
use App\Http\Controllers\API\CategoryController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\CartController;
use App\Http\Controllers\API\OrderController;
use App\Http\Controllers\API\MediaController;
use App\Http\Controllers\API\AddressController;
use App\Http\Controllers\API\CheckoutController;
use App\Http\Controllers\API\RazorpayController;

Route::get('/test', function () {
    return response()->json([
        'status' => true,
        'message' => 'Frontend API working 🚀'
    ]);
});

// Global Media Routes
Route::post('/media/upload', [MediaController::class, 'upload']);
Route::delete('/media/delete', [MediaController::class, 'destroy']);

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
Route::get('/products/featured', [ProductController::class, 'featured']);
Route::get('/products/category/{category_id}', [ProductController::class, 'byCategory']);
Route::get('/products/search', [ProductController::class, 'search']);
Route::get('/products/{slug}', [ProductController::class, 'show']);

// Frontend Cart Routes (Protected)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/cart', [CartController::class, 'index']);
    Route::post('/cart/add', [CartController::class, 'store']);
    Route::PUT('/cart/update-quantity', [CartController::class, 'updateQuantity']);
    Route::delete('/cart/remove/{item_id}', [CartController::class, 'destroy']);
    Route::delete('/cart/clear', [CartController::class, 'clear']);
});

// Frontend Address and Checkout Routes (Protected)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::get('/addresses/{id}', [AddressController::class, 'show']);
    Route::put('/addresses/{id}', [AddressController::class, 'update']);
    Route::delete('/addresses/{id}', [AddressController::class, 'destroy']);
    Route::post('/addresses/{id}/default', [AddressController::class, 'makeDefault']);
    Route::post('/checkout/summary', [CheckoutController::class, 'summary']);
});

// Frontend Order Routes (Protected)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::post('/orders/create', [OrderController::class, 'store']);
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);
    Route::post('/orders/{id}/return', [OrderController::class, 'returnOrder']);
    Route::post('/razorpay/payment/verify', [RazorpayController::class, 'verifyPayment']);
});

Route::post('/razorpay/webhook', [RazorpayController::class, 'webhook']);