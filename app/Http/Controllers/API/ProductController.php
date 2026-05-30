<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    /**
     * Display a listing of all active products.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $products = Product::where('is_active', true)
            ->with(['category', 'images', 'primaryImage', 'sizes'])
            ->orderBy('created_at', 'desc')
            ->paginate(12);

        return response()->json([
            'status' => true,
            'data' => ProductResource::collection($products),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
            'message' => 'Products retrieved successfully'
        ]);
    }

    /**
     * Display the specified product.
     *
     * @param string $slug
     * @return JsonResponse
     */
    public function show(string $slug): JsonResponse
    {
        $product = Product::with(['category', 'images', 'primaryImage', 'sizes'])
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => new ProductResource($product),
            'message' => 'Product retrieved successfully'
        ]);
    }

    /**
     * Display featured products.
     *
     * @return JsonResponse
     */
    public function featured(): JsonResponse
    {
        $featuredProducts = Product::where('is_active', true)
            ->whereHas('sizes', function ($query) {
                $query->where('quantity', '>', 0);
            })
            ->with(['category', 'images', 'primaryImage', 'sizes'])
            ->orderBy('created_at', 'desc')
            ->limit(8)
            ->get();

        return response()->json([
            'status' => true,
            'data' => ProductResource::collection($featuredProducts),
            'message' => 'Featured products retrieved successfully'
        ]);
    }

    /**
     * Display products by category.
     *
     * @param int $categoryId
     * @return JsonResponse
     */
    public function byCategory(int $categoryId): JsonResponse
    {
        $category = Category::where('id', $categoryId)
            ->where('is_active', true)
            ->first();

        if (!$category) {
            return response()->json([
                'status' => false,
                'message' => 'Category not found'
            ], 404);
        }

        $products = Product::where('is_active', true)
            ->where('category_id', $categoryId)
            ->with(['category', 'images', 'primaryImage', 'sizes'])
            ->orderBy('created_at', 'desc')
            ->paginate(12);

        return response()->json([
            'status' => true,
            'data' => [
                'category' => $category,
                'products' => ProductResource::collection($products),
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                ],
            ],
            'message' => 'Products by category retrieved successfully'
        ]);
    }

    /**
     * Search products.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:2|max:100',
            'category_id' => 'nullable|integer|exists:categories,id'
        ]);

        $query = $request->get('q');
        $categoryId = $request->get('category_id');

        $products = Product::where('is_active', true)
            ->where(function ($searchQuery) use ($query) {
                $searchQuery->where('name', 'LIKE', "%{$query}%")
                           ->orWhere('description', 'LIKE', "%{$query}%")
                           ->orWhere('short_description', 'LIKE', "%{$query}%");
            })
            ->when($categoryId, function ($categoryQuery) use ($categoryId) {
                return $categoryQuery->where('category_id', $categoryId);
            })
            ->with(['category', 'images', 'primaryImage', 'sizes'])
            ->orderBy('created_at', 'desc')
            ->paginate(12);

        return response()->json([
            'status' => true,
            'data' => ProductResource::collection($products),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
            'message' => 'Search results retrieved successfully'
        ]);
    }
}
