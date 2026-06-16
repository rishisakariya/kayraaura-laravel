<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScratchCardSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScratchCardSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ScratchCardSetting::current(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'min_discount_percent' => ['required', 'integer', 'min:1', 'max:50'],
            'max_discount_percent' => ['required', 'integer', 'min:1', 'max:50', 'gte:min_discount_percent'],
            'is_active' => ['required', 'boolean'],
        ]);

        $setting = ScratchCardSetting::current();
        $setting->fill($payload)->save();

        return response()->json([
            'success' => true,
            'message' => 'Scratch card settings updated successfully',
            'data' => $setting->refresh(),
        ]);
    }
}
