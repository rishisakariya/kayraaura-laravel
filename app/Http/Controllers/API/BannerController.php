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
        $banners = Banner::orderBy('sort_order')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'data' => BannerResource::collection($banners),
            'message' => 'Banners retrieved successfully',
        ]);
    }
}
