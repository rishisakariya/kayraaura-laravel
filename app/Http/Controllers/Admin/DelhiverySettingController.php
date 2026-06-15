<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DelhiverySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DelhiverySettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => DelhiverySetting::current(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'client_name' => ['required', 'string', 'max:255'],
            'pickup_location' => ['required', 'string', 'max:255'],
            'seller_gst_tin' => ['nullable', 'string', 'max:32'],
            'default_hsn_code' => ['nullable', 'string', 'max:32'],
            'default_length_cm' => ['required', 'integer', 'min:1', 'max:999'],
            'default_width_cm' => ['required', 'integer', 'min:1', 'max:999'],
            'default_height_cm' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $setting = DelhiverySetting::current();
        $setting->fill($payload)->save();

        return response()->json([
            'success' => true,
            'data' => $setting->refresh(),
            'message' => 'Delhivery settings updated successfully',
        ]);
    }
}
