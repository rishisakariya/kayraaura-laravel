<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\CartStoreRequest;
use App\Models\Cart;
use App\Models\Product;
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
            ->with(['product', 'product.category', 'product.images'])
            ->get();

        $total = $cartItems->sum(function ($item) {
            return $item->quantity * ($item->product->sale_price ?? $item->product->price);
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
        $productId = $request->input('product_id');
        $quantity = $request->input('quantity', 1);

        // Check if product exists and is active
        $product = Product::where('id', $productId)
            ->where('is_active', true)
            ->first();

        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found or inactive'
            ], 404);
        }

        // Check stock availability
        if ($product->track_stock && $product->stock_quantity < $quantity) {
            return response()->json([
                'status' => false,
                'message' => 'Insufficient stock available'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Check if item already exists in cart
            $cartItem = Cart::forUser($user->id)
                ->where('product_id', $productId)
                ->first();

            if ($cartItem) {
                // Update existing item
                $newQuantity = $cartItem->quantity + $quantity;
                
                // Check stock again for updated quantity
                if ($product->track_stock && $product->stock_quantity < $newQuantity) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Insufficient stock available for requested quantity'
                    ], 400);
                }

                $cartItem->quantity = $newQuantity;
                $cartItem->save();
                
                $message = 'Cart item updated successfully';
            } else {
                // Add new item to cart
                $cartItem = Cart::create([
                    'user_id' => $user->id,
                    'product_id' => $productId,
                    'quantity' => $quantity
                ]);
                
                $message = 'Item added to cart successfully';
            }

            DB::commit();

            // Load relationships for response
            $cartItem->load(['product', 'product.category', 'product.images']);

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
