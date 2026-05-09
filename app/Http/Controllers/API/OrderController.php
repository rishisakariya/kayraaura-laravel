<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderStoreRequest;
use App\Http\Requests\OrderCancelRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\OrderItemResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    /**
     * Display a listing of the user's orders.
     */
    public function index(): JsonResponse
    {
        $orders = Order::where('user_id', Auth::id())
            ->with('orderItems.product')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => OrderResource::collection($orders),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Store a newly created order in storage.
     */
    public function store(OrderStoreRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $user = Auth::user();
            $items = $request->input('items');
            
            // Calculate order totals
            $subtotal = 0;
            $orderItems = [];

            foreach ($items as $item) {
                $product = Product::findOrFail($item['product_id']);
                
                // Check if product is active and has enough stock
                if (!$product->is_active) {
                    return response()->json([
                        'success' => false,
                        'error' => [
                            'code' => 'PRODUCT_INACTIVE',
                            'message' => "Product '{$product->name}' is not available",
                        ],
                    ], 400);
                }

                if ($product->track_stock && $product->stock_quantity < $item['quantity']) {
                    return response()->json([
                        'success' => false,
                        'error' => [
                            'code' => 'INSUFFICIENT_STOCK',
                            'message' => "Insufficient stock for product '{$product->name}'",
                        ],
                    ], 400);
                }

                $itemTotal = $product->price * $item['quantity'];
                $subtotal += $itemTotal;

                $orderItems[] = [
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'price' => $product->price,
                    'total' => $itemTotal,
                ];
            }

            // Calculate totals (simplified tax and shipping calculation)
            $taxAmount = $subtotal * 0.18; // 18% tax
            $shippingAmount = $subtotal > 1000 ? 0 : 50; // Free shipping over 1000
            $totalAmount = $subtotal + $taxAmount + $shippingAmount;

            // Create order
            $order = Order::create([
                'user_id' => $user->id,
                'order_number' => Order::generateOrderNumber(),
                'status' => 'pending',
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'shipping_amount' => $shippingAmount,
                'total_amount' => $totalAmount,
                'payment_method' => $request->input('payment_method'),
                'payment_status' => 'pending',
                'shipping_address' => $request->input('shipping_address'),
                'billing_address' => $request->input('billing_address') ?: $request->input('shipping_address'),
                'notes' => $request->input('notes'),
            ]);

            // Create order items
            foreach ($orderItems as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'total' => $item['total'],
                ]);

                // Update product stock
                $product = Product::find($item['product_id']);
                if ($product->track_stock) {
                    $product->stock_quantity -= $item['quantity'];
                    $product->save();
                }
            }

            // Clear user's cart
            $user->cart()->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => new OrderResource($order->load('orderItems.product')),
                'message' => 'Order created successfully',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ORDER_CREATION_FAILED',
                    'message' => 'Failed to create order. Please try again.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Display the specified order.
     */
    public function show(string $id): JsonResponse
    {
        $order = Order::where('user_id', Auth::id())
            ->with('orderItems.product')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new OrderResource($order),
        ]);
    }

    /**
     * Cancel the specified order.
     */
    public function cancel(OrderCancelRequest $request, string $id): JsonResponse
    {
        $order = Order::where('user_id', Auth::id())->findOrFail($id);

        if (!$order->canBeCancelled()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ORDER_CANNOT_BE_CANCELLED',
                    'message' => 'This order cannot be cancelled in its current status',
                ],
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Restore product stock
            foreach ($order->orderItems as $item) {
                $product = $item->product;
                if ($product->track_stock) {
                    $product->stock_quantity += $item->quantity;
                    $product->save();
                }
            }

            $order->cancel();

            // Add cancellation reason if provided
            if ($request->input('reason')) {
                $order->notes = ($order->notes ? $order->notes . "\n\n" : '') . 
                               "Cancellation reason: " . $request->input('reason');
                $order->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => new OrderResource($order->load('orderItems.product')),
                'message' => 'Order cancelled successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ORDER_CANCELLATION_FAILED',
                    'message' => 'Failed to cancel order. Please try again.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }
}
