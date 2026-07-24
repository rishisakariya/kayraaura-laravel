<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * Display a listing of all active categories.
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'in:main,sub'],
        ]);
        $type = $validated['type'] ?? null;

        $categories = Category::where('is_active', true)
            ->when($type, function ($query) use ($type) {
                $query->where('type', $type);
            }, function ($query) {
                $query->where('type', 'main');
            })
            ->when(! $type || $type === 'main', function ($query) {
                $query->with(['children' => function ($query) {
                    $query->where('is_active', true)
                        ->where('type', 'sub')
                        ->orderBy('sort_order');
                }]);
            })
            ->when($type === 'sub', function ($query) {
                $query->with(['parent' => function ($query) {
                    $query->where('is_active', true);
                }]);
            })
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $categories,
            'message' => 'Categories retrieved successfully'
        ]);
    }

    /**
     * Display subcategories and products for a main category.
     */
    public function subcategories(int $category_id): JsonResponse
    {
        $mainCategory = Category::where('id', $category_id)
            ->where('type', 'main')
            ->where('is_active', true)
            ->first();

        if (! $mainCategory) {
            return response()->json([
                'status' => false,
                'message' => 'Main category not found',
            ], 404);
        }

        $subCategories = Category::where('parent_id', $mainCategory->id)
            ->where('type', 'sub')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $categoryIds = $subCategories->pluck('id')
            ->push($mainCategory->id)
            ->unique()
            ->values();

        $products = Product::where('is_active', true)
            ->whereIn('category_id', $categoryIds)
            ->with(['category', 'images', 'primaryImage', 'sizes.size'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'data' => [
                'sub_category' => $subCategories,
                'product' => ProductResource::collection($products),
            ],
            'message' => 'Subcategories and products retrieved successfully',
        ]);
    }

    /**
     * Display the specified category.
     *
     * @param string $slug
     * @return JsonResponse
     */
    public function show(string $slug): JsonResponse
    {
        $category = Category::with(['children' => function ($query) {
                $query->where('is_active', true)
                    ->where('type', 'sub')
                    ->orderBy('sort_order');
            }, 'parent'])
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (!$category) {
            return response()->json([
                'status' => false,
                'message' => 'Category not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $category,
            'message' => 'Category retrieved successfully'
        ]);
    }
}
