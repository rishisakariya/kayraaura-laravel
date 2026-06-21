<?php

namespace App\Http\Controllers\Admin;

use App\Support\PublicStorage;
use App\Http\Controllers\Controller;
use App\Http\Resources\WebSettingResource;
use App\Models\WebSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new WebSettingResource(WebSetting::current()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->isAdmin()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Access denied. Admin privileges required.',
                ],
            ], 403);
        }

        $payload = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'address' => ['required', 'string', 'max:5000'],
            'footer_description' => ['nullable', 'string', 'max:5000'],
            'mobile_number' => ['required', 'string', 'max:20'],
            'logo' => ['nullable', 'string', 'max:2048', 'not_regex:/\.\./'],
            'instagram_url' => ['nullable', 'url', 'max:2048'],
            'facebook_url' => ['nullable', 'url', 'max:2048'],
            'youtube_url' => ['nullable', 'url', 'max:2048'],
            'whatsapp_url' => ['nullable', 'url', 'max:2048'],
            'linkedin_url' => ['nullable', 'url', 'max:2048'],
            'buy_two_get_one_free_enabled' => ['sometimes', 'boolean'],
            'first_order_discount_amount' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'online_payment_discount_percent' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        if (!empty($payload['logo'])) {
            $payload['logo'] = PublicStorage::storePath($payload['logo']);
        }

        $setting = WebSetting::current();
        $setting->fill($payload)->save();

        return response()->json([
            'success' => true,
            'message' => 'Web settings updated successfully',
            'data' => new WebSettingResource($setting->refresh()),
        ]);
    }
}
