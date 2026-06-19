<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DelhiverySetting;
use Illuminate\Http\JsonResponse;

class DelhiverySettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => DelhiverySetting::current()->toArray(),
        ]);
    }
}
