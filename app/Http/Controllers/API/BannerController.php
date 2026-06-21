<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;

class BannerController extends Controller
{
    /**
     * Display banners for the frontend.
     */
    public function index(): JsonResponse
    {
        $banner = Banner::current();

        return response()->json([
            'status' => true,
            'data' => $banner ? new BannerResource($banner) : null,
            'message' => 'Banner retrieved successfully',
        ]);
    }
}
