<?php

namespace App\Http\Controllers\Admin;

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
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $products = Product::with(['category', 'images', 'primaryImage', 'sizes.size'])
            ->when($request->input('search'), function ($query, $search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            })
            ->when($request->input('category_id'), function ($query, $categoryId) {
                $query->where('category_id', $categoryId);
            })
            ->when($request->input('is_active') !== null, function ($query, $isActive) {
                $query->where('is_active', $isActive);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

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
            $product->track_stock = $request->input('track_stock', true);
            $product->weight_grams = $request->input('weight_grams');
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
                'data' => new ProductResource($product->load(['category', 'images', 'primaryImage', 'sizes.size']))
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
        $product->track_stock = $request->input('track_stock', $product->track_stock);
        $product->weight_grams = $request->input('weight_grams', $product->weight_grams);
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
            'data' => new ProductResource($product->load(['category', 'images', 'primaryImage', 'sizes.size']))
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
            $this->deleteProductImageFile($image->image_path);
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
        $incomingImages = array_values(array_unique(array_map(function ($image) {
            return $this->normalizePublicStorageUrl($image);
        }, $images)));

        $existingImages = ProductImage::where('product_id', $product->id)->get();
        $existingImagesByUrl = $existingImages->keyBy(function (ProductImage $image) {
            return $this->normalizePublicStorageUrl($image->image_path);
        });

        foreach ($existingImages as $existingImage) {
            $existingImageUrl = $this->normalizePublicStorageUrl($existingImage->image_path);

            if (!in_array($existingImageUrl, $incomingImages, true)) {
                $this->deleteProductImageFile($existingImage->image_path);
                $existingImage->delete();
            }
        }

        foreach ($incomingImages as $index => $image) {
            $existingImage = $existingImagesByUrl->get($image);

            if ($existingImage) {
                $existingImage->image_path = $image;
                $existingImage->alt_text = null;
                $existingImage->sort_order = $index;
                $existingImage->is_primary = $index === 0;
                $existingImage->save();
                continue;
            }

            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $image,
                'alt_text' => null,
                'sort_order' => $index,
                'is_primary' => $index === 0,
            ]);
        }
    }

    private function deleteProductImageFile(string $imagePath): void
    {
        $filePath = $this->normalizePublicDiskPath($imagePath);

        if (Storage::disk('public')->exists($filePath)) {
            Storage::disk('public')->delete($filePath);
        }
    }

    private function normalizePublicDiskPath(string $imagePath): string
    {
        $path = parse_url($imagePath, PHP_URL_PATH) ?: $imagePath;
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            return substr($path, strlen('storage/'));
        }

        return $path;
    }

    private function normalizePublicStorageUrl(string $imagePath): string
    {
        if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
            return $imagePath;
        }

        return Storage::disk('public')->url($this->normalizePublicDiskPath($imagePath));
    }
}
