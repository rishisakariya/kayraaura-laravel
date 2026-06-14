<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\SizeResource;
use App\Models\Size;
use Illuminate\Http\JsonResponse;

class SizeController extends Controller
{
    /**
     * Display sizes available for filtering active products.
     */
    public function index(): JsonResponse
    {
        $sizes = Size::where('is_active', true)
            ->whereHas('productSizes.product', function ($query) {
                $query->where('is_active', true);
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => true,
            'data' => SizeResource::collection($sizes),
            'message' => 'Sizes retrieved successfully',
        ]);
    }
}
