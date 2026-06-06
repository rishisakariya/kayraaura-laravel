<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderStoreRequest;
use App\Http\Requests\OrderCancelRequest;
use App\Http\Requests\OrderReturnRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\ProductSize;
use App\Services\CheckoutService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    public function __construct(private readonly CheckoutService $checkoutService)
    {
    }

    /**
     * Display a listing of the user's orders.
     */
    public function index(): JsonResponse
    {
        $orders = Order::where('user_id', Auth::id())
            ->with(['orderItems.product', 'orderItems.productSize'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'status' => true,
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
            $payload = $request->validated();
            $razorpayCheckout = null;

            $order = DB::transaction(function () use ($payload, &$razorpayCheckout) {
                $user = Auth::user();
                $checkout = $this->checkoutService->buildCheckout($user, $payload, true);
                $order = $this->checkoutService->createOrder($user, $payload, $checkout);

                if ($payload['payment_method'] === 'cod') {
                    $this->checkoutService->deductStockForOrder($order);
                    $this->checkoutService->clearCartIfNeeded($order);

                    return $order;
                }

                $razorpayCheckout = $this->checkoutService->createRazorpayOrder($order);

                return $order;
            });

            $order->load(['orderItems.product', 'orderItems.productSize']);

            return response()->json([
                'status' => true,
                'data' => [
                    'order' => new OrderResource($order),
                    'razorpay' => $razorpayCheckout,
                ],
                'message' => 'Order created successfully',
            ], 201);

        } catch (DomainException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create order. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Display the specified order.
     */
    public function show(string $id): JsonResponse
    {
        $order = Order::where('user_id', Auth::id())
            ->with(['orderItems.product', 'orderItems.productSize'])
            ->findOrFail($id);

        return response()->json([
            'status' => true,
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
                'status' => false,
                'message' => 'Only pending orders can be cancelled',
            ], 400);
        }

        try {
            DB::beginTransaction();

            if ($order->payment_method === 'cod' || $order->payment_status === 'paid') {
                $this->restoreStockForOrder($order);
            }

            if ($order->payment_method === 'online' && $order->payment_status === 'paid') {
                $this->checkoutService->refundRazorpayPayment($order);
                $order->payment_status = 'refunded';
            }

            $order->cancel();

            // Add cancellation reason if provided
            if ($request->input('reason')) {
                $order->notes = ($order->notes ? $order->notes . "\n\n" : '') . 
                               "Cancellation reason: " . $request->input('reason');
            }

            $order->save();

            DB::commit();

            return response()->json([
                'status' => true,
                'data' => new OrderResource($order->load(['orderItems.product', 'orderItems.productSize'])),
                'message' => 'Order cancelled successfully',
            ]);

        } catch (DomainException $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 400);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to cancel order. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Return a delivered order and refund the online payment.
     */
    public function returnOrder(OrderReturnRequest $request, string $id): JsonResponse
    {
        $order = Order::where('user_id', Auth::id())->findOrFail($id);

        if (!$order->canBeReturned()) {
            return response()->json([
                'status' => false,
                'message' => 'Only delivered paid online orders can be returned',
            ], 400);
        }

        try {
            DB::beginTransaction();

            $this->checkoutService->refundRazorpayPayment($order, 'order_returned');
            $this->restoreStockForOrder($order);

            $order->markReturned();
            $order->payment_status = 'refunded';

            if ($request->input('reason')) {
                $order->notes = ($order->notes ? $order->notes . "\n\n" : '') .
                               "Return reason: " . $request->input('reason');
            }

            $order->save();

            DB::commit();

            return response()->json([
                'status' => true,
                'data' => new OrderResource($order->load(['orderItems.product', 'orderItems.productSize'])),
                'message' => 'Order returned and payment refunded successfully',
            ]);

        } catch (DomainException $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 400);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to return order. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function restoreStockForOrder(Order $order): void
    {
        foreach ($order->orderItems as $item) {
            $product = $item->product;
            $productSize = ProductSize::whereKey($item->product_size_id)->lockForUpdate()->first();

            if ($product && $productSize && $product->track_stock) {
                $productSize->increment('quantity', $item->quantity);
            }
        }
    }
}
