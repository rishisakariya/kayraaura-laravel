<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebSettingResource;
use App\Models\WebSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class WebSettingController extends Controller
{
    public function show(): JsonResponse
    {
        $payload = Cache::rememberForever(WebSetting::API_RESPONSE_CACHE_KEY, function () {
            return [
                'success' => true,
                'data' => (new WebSettingResource(WebSetting::current()))->resolve(),
            ];
        });

        return response()
            ->json($payload)
            ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=600');
    }
}
