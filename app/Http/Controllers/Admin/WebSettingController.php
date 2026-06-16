<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebSettingResource;
use App\Models\WebSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
            'mobile_number' => ['required', 'string', 'max:20'],
            'logo' => ['nullable', 'string', 'max:2048', 'not_regex:/\.\./'],
            'buy_two_get_one_free_enabled' => ['sometimes', 'boolean'],
        ]);

        if (!empty($payload['logo'])) {
            $payload['logo'] = $this->normalizePublicStorageUrl($payload['logo']);
        }

        $setting = WebSetting::current();
        $setting->fill($payload)->save();

        return response()->json([
            'success' => true,
            'message' => 'Web settings updated successfully',
            'data' => new WebSettingResource($setting->refresh()),
        ]);
    }

    private function normalizePublicDiskPath(string $filePath): string
    {
        $path = parse_url($filePath, PHP_URL_PATH) ?: $filePath;
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            return substr($path, strlen('storage/'));
        }

        return $path;
    }

    private function normalizePublicStorageUrl(string $filePath): string
    {
        if (filter_var($filePath, FILTER_VALIDATE_URL)) {
            return $filePath;
        }

        return Storage::disk('public')->url($this->normalizePublicDiskPath($filePath));
    }
}
