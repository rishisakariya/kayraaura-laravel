<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        then: function () {
            Route::middleware('api')
                // ->prefix('cpanel')
                ->group(base_path('routes/admin.php'));
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        if (config('delhivery.enabled')) {
            $schedule->command('delhivery:sync-active-shipments')
                ->everyFifteenMinutes()
                ->withoutOverlapping();

            $schedule->command('delhivery:reconcile-failed-shipments')
                ->hourly()
                ->withoutOverlapping();
        }

        if (config('shiprocket.enabled')) {
            $schedule->command('shiprocket:sync-active-shipments')
                ->everyFifteenMinutes()
                ->withoutOverlapping();
        }
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(HandleCors::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
