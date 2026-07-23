<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DelhiverySettingController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\OrderShipmentController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\SizeController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\CustomerReviewController;
use App\Http\Controllers\Admin\WebSettingController;
use App\Http\Controllers\Admin\ScratchCardSettingController;
use App\Http\Controllers\Admin\ScratchCardCouponController;
use App\Http\Controllers\Admin\DashboardController;

Route::get('/cpanel/orders/{id}/shipment/label/download', [OrderShipmentController::class, 'downloadLabel'])
    ->middleware('signed')
    ->name('admin.orders.shipment.label.download'); // Signed URL used to download the shipment label PDF.

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

        // Sizes Management
        Route::apiResource('sizes', SizeController::class)->except(['update']);

        // Banners Management
        Route::get('banners', [BannerController::class, 'show']);
        Route::put('banners', [BannerController::class, 'update']);

        // Customers Management
        Route::get('users', [CustomerController::class, 'index']);
        Route::post('users/{id}/ban', [CustomerController::class, 'ban']);
        Route::put('users/{id}/unban', [CustomerController::class, 'unban']);

        // Customer Reviews Management
        Route::get('customer-reviews', [CustomerReviewController::class, 'index']);
        Route::put('customer-reviews/{customerReview}', [CustomerReviewController::class, 'update']);

        // Delhivery Settings
        Route::get('delhivery-settings', [DelhiverySettingController::class, 'show']);

        // Web Settings
        Route::get('web-settings', [WebSettingController::class, 'show']);
        Route::put('web-settings', [WebSettingController::class, 'update']);

        // Scratch Card Settings
        Route::get('scratch-card-settings', [ScratchCardSettingController::class, 'show']);
        Route::put('scratch-card-settings', [ScratchCardSettingController::class, 'update']);

        // Scratch Card Coupons
        Route::get('scratch-card-coupons', [ScratchCardCouponController::class, 'index']);
        Route::get('scratch-card-coupons/{id}', [ScratchCardCouponController::class, 'show']);

        // Dashboard Analytics
        Route::get('dashboard-payment-method-distribution', [DashboardController::class, 'paymentMethodDistribution']);
        Route::get('dashboard-total-customers', [DashboardController::class, 'totalCustomers']);
        Route::get('dashboard-monthly-sales', [DashboardController::class, 'monthlySales']);
        Route::get('dashboard-top-products', [DashboardController::class, 'topProducts']);
        Route::get('dashboard-gender-distribution', [DashboardController::class, 'genderDistribution']);
        Route::get('dashboard-weekly-order-status', [DashboardController::class, 'weeklyOrderStatus']);

        // Orders Management
        Route::get('order-returns', [OrderController::class, 'returnEntries']);
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{id}', [OrderController::class, 'show']);
        Route::post('orders/{id}/return/refund', [OrderController::class, 'payReturnRefund']);
        Route::post('orders/{id}/shipment/create', [OrderShipmentController::class, 'create']);
        Route::post('orders/{id}/retry-shipment', [OrderShipmentController::class, 'retryShipment']);
        Route::post('orders/{id}/shipment/sync', [OrderShipmentController::class, 'sync']); //this is used to delivery ststus latest sync from delhivery api
        Route::post('orders/{id}/shipment/cancel', [OrderShipmentController::class, 'cancel']);
        Route::get('orders/{id}/shipment/label', [OrderShipmentController::class, 'label']); //this is used to get the shipment label
        Route::post('orders/shipment/labels/bulk', [OrderShipmentController::class, 'bulkLabels']);
    });
});
