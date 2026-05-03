<?php

use Illuminate\Support\Facades\Route;

Route::get('/test', function () {
    return response()->json([
        'status' => true,
        'message' => 'Frontend API working 🚀'
    ]);
});

// Frontend Authentication Routes (will be added later)
// Frontend Product Routes (will be added later)
// Frontend Category Routes (will be added later)
// Frontend Cart Routes (will be added later)
// Frontend Order Routes (will be added later)