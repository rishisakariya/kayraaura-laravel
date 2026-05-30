<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\CartStoreRequest;
use App\Models\Cart;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    /**
     * Display the user's cart.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();

        $cartItems = Cart::forUser($user->id)
            ->with(['product', 'product.category', 'product.images', 'productSize'])
            ->get();

        $total = $cartItems->sum(function ($item) {
            return $item->quantity * (float) ($item->size_price ?? 0);
        });

        return response()->json([
            'status' => true,
            'data' => [
                'items' => $cartItems,
                'total' => $total,
                'item_count' => $cartItems->sum('quantity')
            ],
            'message' => 'Cart retrieved successfully'
        ]);
    }

    /**
     * Add item to cart or update existing item quantity.
     *
     * @param CartStoreRequest $request
     * @return JsonResponse
     */
    public function store(CartStoreRequest $request): JsonResponse
    {
        $user = Auth::user();

        $productSizeId = $request->input('product_size_id');
        $quantity = $request->input('quantity', 1);


        $productSize = \App\Models\ProductSize::with([
            'product' => function ($q) {
                $q->where('is_active', true);
            }
        ])->find($productSizeId);

        if (!$productSize || !$productSize->product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found or inactive'
            ], 404);
        }

        $product = $productSize->product;

        $quantity = $request->input('quantity', 1);

        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found or inactive'
            ], 404);
        }

        // Check stock availability (from size)
        if ($product->track_stock && $productSize->quantity < $quantity) {
            return response()->json([
                'status' => false,
                'message' => 'Insufficient stock available'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Check if item already exists in cart
            $cartItem = Cart::forUser($user->id)
                ->where('product_size_id', $productSize->id)
                ->first();

            if ($cartItem) {
                $newQuantity = $cartItem->quantity + $quantity;

                // Check stock again for updated quantity
                if ($product->track_stock && $productSize->quantity < $newQuantity) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Insufficient stock available for requested quantity'
                    ], 400);
                }

                $cartItem->quantity = $newQuantity;
                $cartItem->size_price = $productSize->price;
                $cartItem->size_text = $productSize->size_text;
                $cartItem->save();

                $message = 'Cart item updated successfully';
            } else {
                $cartItem = Cart::create([
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                    'product_size_id' => $productSize->id,
                    'size_text' => $productSize->size_text,
                    'size_price' => $productSize->price,
                    'quantity' => $quantity,
                ]);

                $message = 'Item added to cart successfully';
            }

            DB::commit();

            // Load relationships for response
            $cartItem->load(['product', 'product.category', 'product.images', 'productSize']);

            return response()->json([
                'status' => true,
                'data' => $cartItem,
                'message' => $message
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to add item to cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove item from cart.
     *
     * @param int $item_id
     * @return JsonResponse
     */
    public function destroy(int $item_id): JsonResponse
    {
        $user = Auth::user();

        $cartItem = Cart::forUser($user->id)
            ->where('id', $item_id)
            ->first();

        if (!$cartItem) {
            return response()->json([
                'status' => false,
                'message' => 'Cart item not found'
            ], 404);
        }

        $cartItem->delete();

        return response()->json([
            'status' => true,
            'message' => 'Item removed from cart successfully'
        ]);
    }

    /**
     * Clear the entire cart.
     *
     * @return JsonResponse
     */
    public function clear(): JsonResponse
    {
        $user = Auth::user();

        Cart::forUser($user->id)->delete();

        return response()->json([
            'status' => true,
            'message' => 'Cart cleared successfully'
        ]);
    }
}
