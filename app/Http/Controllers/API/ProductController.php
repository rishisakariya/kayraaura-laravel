<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    /**
     * Display a listing of all active products.
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $this->validatedProductFilters($request);

        $products = Product::where('is_active', true)
            ->tap(fn (Builder $query) => $this->applyProductFilters($query, $filters))
            ->with(['category', 'images', 'primaryImage', 'sizes.size'])
            ->orderBy('name', 'asc')
            ->paginate($this->perPage($request));

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

    private function validatedProductFilters(Request $request): array
    {
        $validated = $request->validate([
            'price' => ['nullable', 'regex:/^\d+(\.\d{1,2})?-\d+(\.\d{1,2})?$/'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'category_type' => ['nullable', 'in:main,sub'],
            'size_id' => ['nullable', 'integer', 'exists:sizes,id'],
        ]);

        [$minPrice, $maxPrice] = $this->priceBounds($validated);

        if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
            throw ValidationException::withMessages([
                'price' => ['The minimum price must be less than or equal to the maximum price.'],
            ]);
        }

        return [
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'category_id' => $validated['category_id'] ?? null,
            'category_type' => $validated['category_type'] ?? null,
            'size_id' => $validated['size_id'] ?? null,
        ];
    }

    private function applyProductFilters(Builder $query, array $filters): void
    {
        $minPrice = $filters['min_price'];
        $maxPrice = $filters['max_price'];
        $categoryId = $filters['category_id'];
        $categoryType = $filters['category_type'];
        $sizeId = $filters['size_id'];

        $query
            ->when($minPrice !== null || $maxPrice !== null || $sizeId, function ($productQuery) use ($minPrice, $maxPrice, $sizeId) {
                $productQuery->whereHas('sizes', function ($sizeQuery) use ($minPrice, $maxPrice, $sizeId) {
                    $sizeQuery
                        ->when($sizeId, fn ($query) => $query->where('size_id', $sizeId))
                        ->when($minPrice !== null, fn ($query) => $query->where('price', '>=', $minPrice))
                        ->when($maxPrice !== null, fn ($query) => $query->where('price', '<=', $maxPrice));
                });
            })
            ->when($categoryId, function ($productQuery) use ($categoryId) {
                $productQuery->whereHas('category', function ($categoryQuery) use ($categoryId) {
                    $categoryQuery->where('id', $categoryId)->where('is_active', true);
                });
            })
            ->when($categoryType, function ($productQuery) use ($categoryType) {
                $productQuery->whereHas('category', function ($categoryQuery) use ($categoryType) {
                    $categoryQuery->where('type', $categoryType)->where('is_active', true);
                });
            });
    }

    private function priceBounds(array $filters): array
    {
        if (!empty($filters['price'])) {
            return array_map('floatval', explode('-', $filters['price'], 2));
        }

        return [
            isset($filters['min_price']) ? (float) $filters['min_price'] : null,
            isset($filters['max_price']) ? (float) $filters['max_price'] : null,
        ];
    }

    /**
     * Display the specified product.
     *
     * @param string $slug
     * @return JsonResponse
     */
    public function show(string $slug): JsonResponse
    {
        $product = Product::with(['category', 'images', 'primaryImage', 'sizes.size', 'webReviews.user'])
            ->withCount('reviews')
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
            ->with(['category', 'images', 'primaryImage', 'sizes.size'])
            ->orderBy('name', 'asc')
            ->limit(8)
            ->get();

        return response()->json([
            'status' => true,
            'data' => ProductResource::collection($featuredProducts),
            'message' => 'Featured products retrieved successfully'
        ]);
    }

    /**
     * Display collection products.
     *
     * @return JsonResponse
     */
    public function collection(Request $request): JsonResponse
    {
        $filters = $this->validatedProductFilters($request);

        $products = Product::where('is_active', true)
            ->where('is_collection', true)
            ->tap(fn (Builder $query) => $this->applyProductFilters($query, $filters))
            ->with(['category', 'images', 'primaryImage', 'sizes.size'])
            ->orderBy('name', 'asc')
            ->paginate($this->perPage($request));

        return response()->json([
            'status' => true,
            'data' => ProductResource::collection($products),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
            'message' => 'Collection products retrieved successfully'
        ]);
    }

    /**
     * Display products by category.
     *
     * @param int $categoryId
     * @return JsonResponse
     */
    public function byCategory(Request $request, int $categoryId): JsonResponse
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
            ->with(['category', 'images', 'primaryImage', 'sizes.size'])
            ->orderBy('name', 'asc')
            ->paginate($this->perPage($request));

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

    private function perPage(Request $request): int
    {
        $validated = validator($request->only('per_page'), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])->validate();

        return $validated['per_page'] ?? 12;
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
            'category_id' => 'nullable|integer|exists:categories,id',
            'category_type' => 'nullable|in:main,sub',
        ]);

        $query = $request->get('q');
        $categoryId = $request->get('category_id');
        $categoryType = $request->get('category_type');

        $products = Product::where('is_active', true)
            ->where(function ($searchQuery) use ($query) {
                $searchQuery->where('name', 'LIKE', "%{$query}%")
                           ->orWhere('description', 'LIKE', "%{$query}%")
                           ->orWhere('short_description', 'LIKE', "%{$query}%");
            })
            ->when($categoryId, function ($categoryQuery) use ($categoryId) {
                return $categoryQuery->where('category_id', $categoryId);
            })
            ->when($categoryType, function ($productQuery) use ($categoryType) {
                return $productQuery->whereHas('category', function ($categoryQuery) use ($categoryType) {
                    $categoryQuery->where('type', $categoryType)->where('is_active', true);
                });
            })
            ->with(['category', 'images', 'primaryImage', 'sizes.size'])
            ->orderBy('name', 'asc')
            ->paginate($this->perPage($request));

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

    /**
     * Search active products by product name only.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function searchByName(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|min:2|max:100',
        ]);

        $name = $request->get('name');

        $products = Product::where('is_active', true)
            ->where('name', 'LIKE', "%{$name}%")
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json([
            'status' => true,
            'data' => $products,
            'message' => 'Product name search results retrieved successfully'
        ]);
    }
}
