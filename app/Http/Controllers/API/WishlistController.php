<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\WishlistStoreRequest;
use App\Http\Resources\WishlistResource;
use App\Models\Product;
use App\Models\Wishlist;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WishlistController extends Controller
{
    public function index(): JsonResponse
    {
        $wishlistItems = Wishlist::forUser(Auth::id())
            ->with(['product.category', 'product.images', 'product.sizes.size'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'status' => true,
            'data' => WishlistResource::collection($wishlistItems),
            'pagination' => [
                'current_page' => $wishlistItems->currentPage(),
                'last_page' => $wishlistItems->lastPage(),
                'per_page' => $wishlistItems->perPage(),
                'total' => $wishlistItems->total(),
            ],
            'message' => 'Wishlist retrieved successfully',
        ]);
    }

    public function store(WishlistStoreRequest $request): JsonResponse
    {
        $userId = Auth::id();
        $product = $this->activeProduct((int) $request->input('product_id'));

        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found or inactive',
            ], 404);
        }

        try {
            $wishlistItem = DB::transaction(function () use ($userId, $product) {
                $existingItem = Wishlist::forUser($userId)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();

                if ($existingItem) {
                    throw new DomainException('Product is already in wishlist');
                }

                return Wishlist::create([
                    'user_id' => $userId,
                    'product_id' => $product->id,
                ]);
            });

            $wishlistItem->load(['product.category', 'product.images', 'product.sizes.size']);

            return response()->json([
                'status' => true,
                'data' => new WishlistResource($wishlistItem),
                'message' => 'Product added to wishlist successfully',
            ], 201);

        } catch (DomainException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 409);
        }
    }

    public function show(int $id): JsonResponse
    {
        $wishlistItem = Wishlist::forUser(Auth::id())
            ->with(['product.category', 'product.images', 'product.sizes.size'])
            ->find($id);

        if (!$wishlistItem) {
            return response()->json([
                'status' => false,
                'message' => 'Wishlist item not found',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => new WishlistResource($wishlistItem),
            'message' => 'Wishlist item retrieved successfully',
        ]);
    }

    public function update(WishlistStoreRequest $request, int $id): JsonResponse
    {
        $userId = Auth::id();
        $product = $this->activeProduct((int) $request->input('product_id'));

        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found or inactive',
            ], 404);
        }

        try {
            $wishlistItem = DB::transaction(function () use ($userId, $id, $product) {
                $wishlistItem = Wishlist::forUser($userId)
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (!$wishlistItem) {
                    return null;
                }

                $duplicateItem = Wishlist::forUser($userId)
                    ->where('product_id', $product->id)
                    ->whereKeyNot($wishlistItem->id)
                    ->lockForUpdate()
                    ->first();

                if ($duplicateItem) {
                    throw new DomainException('Product is already in wishlist');
                }

                $wishlistItem->update([
                    'product_id' => $product->id,
                ]);

                return $wishlistItem;
            });

            if (!$wishlistItem) {
                return response()->json([
                    'status' => false,
                    'message' => 'Wishlist item not found',
                ], 404);
            }

            $wishlistItem->load(['product.category', 'product.images', 'product.sizes.size']);

            return response()->json([
                'status' => true,
                'data' => new WishlistResource($wishlistItem),
                'message' => 'Wishlist item updated successfully',
            ]);

        } catch (DomainException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 409);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $wishlistItem = Wishlist::forUser(Auth::id())->find($id);

        if (!$wishlistItem) {
            return response()->json([
                'status' => false,
                'message' => 'Wishlist item not found',
            ], 404);
        }

        $wishlistItem->delete();

        return response()->json([
            'status' => true,
            'message' => 'Product removed from wishlist successfully',
        ]);
    }

    public function clear(): JsonResponse
    {
        Wishlist::forUser(Auth::id())->delete();

        return response()->json([
            'status' => true,
            'message' => 'Wishlist cleared successfully',
        ]);
    }

    private function activeProduct(int $productId): ?Product
    {
        return Product::where('is_active', true)->find($productId);
    }
}
