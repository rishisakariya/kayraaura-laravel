<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/run-upload-link', function () {
    Artisan::call('uploads:ensure-link --migrate');
    return Artisan::output();
});