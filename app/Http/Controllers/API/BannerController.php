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
        return response()->json([
            'status' => true,
            'data' => new BannerResource(Banner::current()),
            'message' => 'Banner retrieved successfully',
        ]);
    }
}
