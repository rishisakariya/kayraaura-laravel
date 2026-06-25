<?php

namespace App\Http\Controllers\Admin;

use App\Support\PublicStorage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductStoreRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductImageResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Http\Controllers\Admin\ProductSizePersistor;
use Illuminate\Http\JsonResponse;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'price' => ['nullable', 'regex:/^\d+(\.\d{1,2})?-\d+(\.\d{1,2})?$/'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'size_id' => ['nullable', 'integer', 'exists:sizes,id'],
            'is_active' => ['nullable', 'boolean'],
            'is_collection' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        [$minPrice, $maxPrice] = $this->priceBounds($validated);

        if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
            throw ValidationException::withMessages([
                'price' => ['The minimum price must be less than or equal to the maximum price.'],
            ]);
        }

        $search = $validated['search'] ?? null;
        $categoryId = $validated['category_id'] ?? null;
        $sizeId = $validated['size_id'] ?? null;

        $products = Product::with(['category', 'images', 'primaryImage', 'sizes.size'])
            ->when($search, function ($query) use ($search) {
                $query->where(function ($searchQuery) use ($search) {
                    $searchQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($categoryId, function ($query) use ($categoryId) {
                $query->where('category_id', $categoryId);
            })
            ->when($minPrice !== null || $maxPrice !== null || $sizeId, function ($query) use ($minPrice, $maxPrice, $sizeId) {
                $query->whereHas('sizes', function ($sizeQuery) use ($minPrice, $maxPrice, $sizeId) {
                    $sizeQuery
                        ->when($sizeId, fn ($query) => $query->where('size_id', $sizeId))
                        ->when($minPrice !== null, fn ($query) => $query->where('price', '>=', $minPrice))
                        ->when($maxPrice !== null, fn ($query) => $query->where('price', '<=', $maxPrice));
                });
            })
            ->when(array_key_exists('is_active', $validated), function ($query) use ($validated) {
                $query->where('is_active', $validated['is_active']);
            })
            ->when(array_key_exists('is_collection', $validated), function ($query) use ($validated) {
                $query->where('is_collection', $validated['is_collection']);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => ProductResource::collection($products),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ]
        ]);
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
     * Store a newly created resource in storage.
     */
    public function store(ProductStoreRequest $request): JsonResponse
    {
        if ((int) $request->input('edit_value') === 0) {
            // Create new product
            DB::beginTransaction();
            $product = new Product;
            $product->name = $request->input('name');
            $product->slug = $request->input('slug') ?: null;
            $product->description = $request->input('description');
            $product->short_description = $request->input('short_description');

            $this->fillSpecificationFields($product, $request);
            $product->category_id = $request->input('category_id');
            $product->discount_percentage = $request->input('discount_percentage');
            $product->is_active = $request->input('is_active', true);
            $product->is_collection = $request->input('is_collection', false);
            $product->weight_grams = $request->input('weight_grams');
            $product->review_count = $request->input('review_count');
            $product->save();

            // Persist sizes
            if ($request->has('sizes')) {
                app(ProductSizePersistor::class)->replaceForProduct($product->id, $request->input('sizes', []));
            }

            if ($request->has('image')) {
                $this->syncProductImages($product, $request->input('image', []));
            }

            DB::commit();


            return response()->json([
                'success' => true,
                'message' => 'Product created successfully',
                'data' => $this->productResponseData($product),
            ]);
        }

        // Update existing product
        try {
            $product = Product::findOrFail($request->input('edit_value'));
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        DB::beginTransaction();
        $product->name = $request->input('name');
        $product->slug = $request->input('slug') ?: null;
        $product->description = $request->input('description');
        $product->short_description = $request->input('short_description');

        $this->fillSpecificationFields($product, $request);
        $product->category_id = $request->input('category_id');
        $product->discount_percentage = $request->input('discount_percentage');
        $product->is_active = $request->input('is_active', $product->is_active);
        $product->is_collection = $request->input('is_collection', $product->is_collection);
        $product->weight_grams = $request->input('weight_grams', $product->weight_grams);
        $product->review_count = $request->input('review_count', $product->review_count);
        $product->save();

        // Persist sizes
        if ($request->has('sizes')) {
            app(ProductSizePersistor::class)->replaceForProduct($product->id, $request->input('sizes', []));
        }

        if ($request->has('image')) {
            $this->syncProductImages($product, $request->input('image', []));
        }


        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully',
            'data' => $this->productResponseData($product),
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $product = Product::with(['category', 'images', 'primaryImage', 'sizes.size'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => new ProductResource($product)
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        DB::beginTransaction();

        foreach ($product->images as $image) {
            PublicStorage::delete($image->image_path);
        }

        // Delete product (images will be deleted via cascade)
        $product->delete();

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully'
        ]);
    }

    private function fillSpecificationFields(Product $product, ProductStoreRequest $request): void
    {
        foreach ([
            'brand',
            'base_material',
            'plating',
            'gemstone',
            'design',
            'occasion',
            'ideal_for',
            'package_contents',
        ] as $field) {
            $product->{$field} = $request->input($field);
        }
    }

    private function syncProductImages(Product $product, array $images): void
    {
        $incomingImages = [];
        $seenPaths = [];

        foreach ($images as $image) {
            $path = PublicStorage::normalizeInput($image) ?? (is_string($image) ? PublicStorage::storePath($image) : null);

            if ($path === null || isset($seenPaths[$path])) {
                continue;
            }

            $seenPaths[$path] = true;
            $incomingImages[] = $path;
        }

        $incomingDiskPaths = array_map(
            fn (string $path) => PublicStorage::diskPath($path),
            $incomingImages
        );

        $existingImages = ProductImage::where('product_id', $product->id)->get();

        foreach ($existingImages as $existingImage) {
            $existingDiskPath = PublicStorage::diskPath($existingImage->image_path);

            if (!in_array($existingDiskPath, $incomingDiskPaths, true)) {
                PublicStorage::delete($existingImage->image_path);
                $existingImage->delete();
            }
        }

        $existingImagesByPath = ProductImage::where('product_id', $product->id)
            ->get()
            ->keyBy(fn (ProductImage $image) => PublicStorage::diskPath($image->image_path) ?? '');

        foreach ($incomingImages as $index => $path) {
            $diskPath = PublicStorage::diskPath($path);
            $existingImage = $diskPath !== null ? $existingImagesByPath->get($diskPath) : null;

            if ($existingImage instanceof ProductImage) {
                $existingImage->image_path = $path;
                $existingImage->alt_text = null;
                $existingImage->sort_order = $index;
                $existingImage->is_primary = $index === 0;
                $existingImage->save();

                continue;
            }

            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $path,
                'alt_text' => null,
                'sort_order' => $index,
                'is_primary' => $index === 0,
            ]);
        }
    }

    private function productResponseData(Product $product): ProductResource
    {
        $product->unsetRelation('images');
        $product->unsetRelation('primaryImage');

        return new ProductResource($product->load(['category', 'images', 'primaryImage', 'sizes.size']));
    }
}
