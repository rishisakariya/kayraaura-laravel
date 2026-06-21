<?php

namespace App\Providers;

use App\Models\OrderShipment;
use App\Observers\OrderShipmentObserver;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Hostinger: Laravel root is public_html (no separate public/ folder).
        $this->app->usePublicPath(base_path());

        OrderShipment::observe(OrderShipmentObserver::class);

        Route::prefix('api')
            ->middleware('api')
            ->group(base_path('routes/api.php'));
    }
}
