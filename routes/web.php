<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// One-time Hostinger setup via browser (remove this route after use).
// Visit: /run-upload-link?token=YOUR_UPLOADS_SETUP_TOKEN
Route::get('/run-upload-link', function () {
    $token = config('filesystems.disks.uploads.setup_token');

    if (!$token || !hash_equals($token, (string) request()->query('token', ''))) {
        abort(403, 'Forbidden');
    }

    $exitCode = Artisan::call('uploads:ensure-link', ['--migrate' => true]);

    return response(
        Artisan::output(),
        $exitCode === 0 ? 200 : 500,
        ['Content-Type' => 'text/plain; charset=UTF-8'],
    );
});
