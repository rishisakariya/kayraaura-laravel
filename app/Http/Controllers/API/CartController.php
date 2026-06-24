<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\CartStoreRequest;
use App\Http\Requests\CartUpdateQuantityRequest;
use App\Http\Resources\CartResource;
use App\Models\Cart;
use App\Models\ProductSize;
use App\Services\CheckoutService;
use App\Services\ScratchCardService;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CartController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly ScratchCardService $scratchCardService,
    ) {
    }

    /**
     * Display the user's cart.
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'payment_method' => ['nullable', Rule::in(['cod', 'online'])],
            'coupon_code' => ['nullable', 'string', 'size:6'],
        ]);

        try {
            $user = Auth::user();

            $cartItems = Cart::forUser($user->id)
                ->with(['product', 'product.category', 'product.images', 'productSize.size'])
                ->get();

            $summary = $this->checkoutService->buildCartSummary(
                $user,
                $cartItems,
                $request->input('payment_method')
            );
            $summary = $this->scratchCardService->applyCouponToCheckout(
                $user,
                $summary,
                $request->input('coupon_code')
            );

            return response()->json([
                'status' => true,
                'data' => [
                    'items' => CartResource::collection($cartItems),
                    'items_subtotal' => $summary['items_subtotal'],
                    'subtotal' => $summary['subtotal'],
                    'tax_amount' => $summary['tax_amount'],
                    'shipping_amount' => $summary['shipping_amount'],
                    'buy_two_get_one_free_enabled' => $summary['buy_two_get_one_free_enabled'],
                    'buy_two_get_one_discount_amount' => $summary['buy_two_get_one_discount_amount'],
                    'first_order_discount_eligible' => $summary['first_order_discount_eligible'],
                    'first_order_discount_amount' => $summary['first_order_discount_amount'],
                    'online_payment_discount_percent' => $summary['online_payment_discount_percent'] ?? null,
                    'online_payment_discount_amount' => $summary['online_payment_discount_amount'] ?? 0,
                    'cod_charge' => $summary['cod_charge'],
                    'scratch_card_enabled' => $this->scratchCardService->isActive(),
                    'scratch_coupon_code' => $summary['coupon_code'] ?? null,
                    'discount_percent' => $summary['discount_percent'] ?? null,
                    'discount_amount' => $summary['discount_amount'] ?? 0,
                    'total' => $summary['final_total_amount'] ?? $summary['total_amount'],
                    'item_count' => $cartItems->sum('quantity'),
                ],
                'message' => 'Cart retrieved successfully',
            ]);
        } catch (DomainException $e) {
            if (!empty($request->input('coupon_code')) && !$this->scratchCardService->isActive()) {
                return response()->json([
                    'status' => false,
                    'message' => $e->getMessage(),
                    'error' => ['code' => 'SCRATCH_CARD_DISABLED'],
                ], 403);
            }

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Add item to cart.
     *
     * @param CartStoreRequest $request
     * @return JsonResponse
     */
    public function store(CartStoreRequest $request): JsonResponse
    {
        $user = Auth::user();

        $productSizeId = $request->input('product_size_id');
        $quantity = $request->input('quantity', 1);

        $productSize = ProductSize::with([
            'product' => function ($q) {
                $q->where('is_active', true);
            },
            'size',
        ])->find($productSizeId);

        if (!$productSize || !$productSize->product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found or inactive'
            ], 404);
        }

        $product = $productSize->product;

        try {
            $cartItem = DB::transaction(function () use ($user, $product, $productSize, $quantity) {
                $cartItem = Cart::forUser($user->id)
                    ->where('product_size_id', $productSize->id)
                    ->lockForUpdate()
                    ->first();

                if ($cartItem) {
                    throw new DomainException('Product is already in cart');
                }

                if ($productSize->quantity < $quantity) {
                    throw new DomainException('Insufficient stock available for requested quantity');
                }

                return Cart::create([
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                    'product_size_id' => $productSize->id,
                    'size_text' => $productSize->size?->name ?? $productSize->size_text,
                    'size_price' => $productSize->price,
                    'quantity' => $quantity,
                ]);
            });

            // Load relationships for response
            $cartItem->load(['product', 'product.category', 'product.images', 'productSize.size']);

            return response()->json([
                'status' => true,
                'data' => new CartResource($cartItem),
                'message' => 'Item added to cart successfully'
            ]);

        } catch (DomainException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], $e->getMessage() === 'Product is already in cart' ? 409 : 400);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to add item to cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Set cart item quantity by product size.
     */
    public function updateQuantity(CartUpdateQuantityRequest $request): JsonResponse
    {
        $user = Auth::user();
        $productSizeId = $request->input('product_size_id');
        $quantity = (int) $request->input('quantity');

        $cartItem = Cart::forUser($user->id)
            ->where('product_size_id', $productSizeId)
            ->with(['product', 'product.category', 'product.images', 'productSize.size'])
            ->first();

        if (!$cartItem) {
            return response()->json([
                'status' => false,
                'message' => 'Cart item not found'
            ], 404);
        }

        if ($quantity === 0) {
            $cartItem->delete();

            return response()->json([
                'status' => true,
                'message' => 'Item removed from cart successfully'
            ]);
        }

        $productSize = $cartItem->productSize;
        $product = $cartItem->product;

        if (!$productSize || !$product || !$product->is_active) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found or inactive'
            ], 404);
        }

        if ($productSize->quantity < $quantity) {
            return response()->json([
                'status' => false,
                'message' => 'Insufficient stock available for requested quantity'
            ], 400);
        }

        $cartItem->update([
            'product_id' => $product->id,
            'size_text' => $productSize->size?->name ?? $productSize->size_text,
            'size_price' => $productSize->price,
            'quantity' => $quantity,
        ]);

        $cartItem->load(['product', 'product.category', 'product.images', 'productSize.size']);

        return response()->json([
            'status' => true,
            'data' => new CartResource($cartItem),
            'message' => 'Cart item quantity updated successfully'
        ]);
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
