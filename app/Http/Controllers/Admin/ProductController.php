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
        $products = Product::with(['category', 'images'])
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
            $product->price = $request->input('price');
            $product->sale_price = $request->input('sale_price');
            $product->cost_price = $request->input('cost_price');
            $product->category_id = $request->input('category_id');
            $product->is_active = $request->input('is_active', true);
            $product->stock_quantity = $request->input('stock_quantity', 0);
            $product->track_stock = $request->input('track_stock', true);
            $product->save();

            // Persist sizes
            if ($request->has('sizes')) {
                app(ProductSizePersistor::class)->replaceForProduct($product->id, $request->input('sizes', []));
            }

            // Handle new images
            if ($request->hasFile('images')) {
                $this->handleProductImages($request->file('images'), $product->id);
            }

            DB::commit();


            return response()->json([
                'success' => true,
                'message' => 'Product created successfully',
                'data' => new ProductResource($product->load(['category', 'images']))
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
        $product->price = $request->input('price');
        $product->sale_price = $request->input('sale_price');
        $product->cost_price = $request->input('cost_price');
        $product->category_id = $request->input('category_id');
        $product->is_active = $request->input('is_active', $product->is_active);
        $product->stock_quantity = $request->input('stock_quantity', $product->stock_quantity);
        $product->track_stock = $request->input('track_stock', $product->track_stock);
        $product->save();

        // Persist sizes
        if ($request->has('sizes')) {
            app(ProductSizePersistor::class)->replaceForProduct($product->id, $request->input('sizes', []));
        }

        // Handle new images
        if ($request->hasFile('images')) {
            $this->handleProductImages($request->file('images'), $product->id);
        }


        // Handle existing images updates
        if ($request->has('existing_images')) {
            $this->updateExistingImages($request->input('existing_images'));
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully',
            'data' => new ProductResource($product->load(['category', 'images']))
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $product = Product::with(['category', 'images'])
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

        // Delete product images from storage
        foreach ($product->images as $image) {
            Storage::disk('public')->delete($image->image_path);
        }

        // Delete product (images will be deleted via cascade)
        $product->delete();

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully'
        ]);
    }

    /**
     * Handle product image uploads
     */
    private function handleProductImages($images, $productId): void
    {
        foreach ($images as $index => $image) {
            $path = $image->store('products/' . $productId, 'public');

            $productImage = new ProductImage;
            $productImage->product_id = $productId;
            $productImage->image_path = $path;
            $productImage->alt_text = $image->getClientOriginalName();
            $productImage->sort_order = $index;
            $productImage->is_primary = $index === 0; // First image is primary
            $productImage->save();
        }
    }

    /**
     * Update existing product images
     */
    private function updateExistingImages($existingImages): void
    {
        foreach ($existingImages as $imageData) {
            $image = ProductImage::find($imageData['id']);
            if ($image) {
                $image->alt_text = $imageData['alt_text'] ?? $image->alt_text;
                $image->sort_order = $imageData['sort_order'] ?? $image->sort_order;
                $image->is_primary = $imageData['is_primary'] ?? $image->is_primary;
                $image->save();
            }
        }
    }
}
