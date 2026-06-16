<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderStoreRequest;
use App\Http\Requests\OrderCancelRequest;
use App\Http\Requests\OrderReturnRequest;
use App\Http\Resources\OrderResource;
use App\Jobs\CancelDelhiveryShipmentJob;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\ProductSize;
use App\Models\UserAddress;
use App\Services\CheckoutService;
use App\Services\Delhivery\DelhiveryShipmentService;
use App\Services\OtpService;
use App\Services\ScratchCardService;
use App\Services\Shiprocket\ShiprocketShipmentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly DelhiveryShipmentService $shipmentService,
        private readonly ShiprocketShipmentService $shiprocketShipmentService,
        private readonly OtpService $otpService,
        private readonly ScratchCardService $scratchCardService,
    )
    {
    }

    /**
     * Display a listing of the user's orders.
     */
    public function index(): JsonResponse
    {
        $orders = Order::where('user_id', Auth::id())
            ->where('payment_status', '!=', 'pending')
            ->with(['orderItems.product.images', 'orderItems.productSize', 'shipment'])
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
                $checkout = $this->scratchCardService->applyCouponToCheckout(
                    $user,
                    $checkout,
                    $payload['coupon_code'] ?? null
                );
                $coupon = $checkout['scratch_coupon'] ?? null;

                if ($payload['payment_method'] === 'cod') {
                    $this->otpService->verifyAndConsume(
                        $checkout['address']->phone,
                        OtpService::PURPOSE_COD_ORDER,
                        $payload['cod_otp']
                    );
                }

                $order = $this->checkoutService->createOrder($user, $payload, $checkout);

                if ($coupon) {
                    $this->scratchCardService->redeem(
                        $user,
                        $coupon->code,
                        $order->id,
                        $checkout['discount_amount'] ?? null
                    );
                }

                if ($payload['payment_method'] === 'cod') {
                    $this->checkoutService->deductStockForOrder($order);
                    $this->checkoutService->clearCartIfNeeded($order);

                    return $order;
                }

                $razorpayCheckout = $this->checkoutService->createRazorpayOrder($order);

                return $order;
            });

            $order->load(['orderItems.product.images', 'orderItems.productSize', 'shipment']);

            return response()->json([
                'status' => true,
                'data' => [
                    'order' => new OrderResource($order),
                    'razorpay' => $razorpayCheckout,
                ],
                'message' => $order->payment_method === 'cod'
                    ? 'Order placed successfully. Your COD order is pending confirmation.'
                    : 'Order created successfully',
            ], 201);

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

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create order. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Send OTP before placing a COD order.
     */
    public function sendCodOtp(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'address_id' => 'required|integer|exists:user_addresses,id',
        ]);

        try {
            $address = UserAddress::where('user_id', Auth::id())->find($payload['address_id']);

            if (!$address) {
                return response()->json([
                    'status' => false,
                    'message' => 'Selected address was not found',
                ], 404);
            }

            $this->otpService->send(
                $address->phone,
                OtpService::PURPOSE_COD_ORDER
            );

            return response()->json([
                'status' => true,
                'message' => 'COD confirmation OTP sent to delivery mobile number',
            ]);

        } catch (DomainException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 429);
        }
    }

    /**
     * Display the specified order.
     */
    public function show(string $id): JsonResponse
    {
        $order = Order::where('user_id', Auth::id())
            ->with(['orderItems.product.images', 'orderItems.productSize', 'shipment'])
            ->firstWhere('id', $id);

        if (!$order) {
            return response()->json([
                'status' => false,
                'message' => 'Order not found for the authenticated customer.',
                'hint' => 'Use the customer token that owns this order, or use the admin order detail endpoint.',
            ], 404);
        }

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
        $order = Order::where('user_id', Auth::id())->with('shipment')->findOrFail($id);

        if (!$order->canBeCancelled()) {
            return response()->json([
                'status' => false,
                'message' => 'Only pending orders can be cancelled',
            ], 400);
        }

        try {
            $shipmentToCancel = null;

            DB::beginTransaction();

            if ($order->payment_method === 'cod' || $order->payment_status === 'paid') {
                $this->restoreStockForOrder($order);
            }

            if ($order->payment_method === 'online' && $order->payment_status === 'paid') {
                $this->checkoutService->refundRazorpayPayment($order);
                $order->payment_status = 'refunded';
            }

            $order->cancel();

            if ($order->shipment?->waybill && !in_array($order->shipment->shipment_status, [
                OrderShipment::STATUS_DELIVERED,
                OrderShipment::STATUS_CANCELLED,
                OrderShipment::STATUS_RTO,
            ], true)) {
                $shipmentToCancel = $order->shipment;
            }

            // Add cancellation reason if provided
            if ($request->input('reason')) {
                $order->notes = ($order->notes ? $order->notes . "\n\n" : '') . 
                               "Cancellation reason: " . $request->input('reason');
            }

            $order->save();

            DB::commit();

            if ($shipmentToCancel) {
                CancelDelhiveryShipmentJob::dispatch($shipmentToCancel->id);
            }

            return response()->json([
                'status' => true,
                'data' => new OrderResource($order->load(['orderItems.product.images', 'orderItems.productSize', 'shipment'])),
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
        $order = Order::where('user_id', Auth::id())
            ->with(['shipment', 'orderItems.product'])
            ->findOrFail($id);

        if (!$order->canBeReturned()) {
            return response()->json([
                'status' => false,
                'message' => 'Only delivered orders can be returned',
            ], 400);
        }

        if (!$order->shipment?->delivered_at) {
            return response()->json([
                'status' => false,
                'message' => 'Delivery date is not available for this order',
            ], 400);
        }

        if (now()->gt($order->shipment->delivered_at->copy()->addDays(7))) {
            return response()->json([
                'status' => false,
                'message' => 'Return window expired. Returns are allowed within 7 days from delivery',
            ], 400);
        }

        $transactionStarted = false;

        try {
            $service = $order->shipment?->provider === OrderShipment::PROVIDER_SHIPROCKET
                ? $this->shiprocketShipmentService
                : $this->shipmentService;

            $service->createReversePickup($order);

            DB::beginTransaction();
            $transactionStarted = true;

            if ($order->payment_method === 'online' && $order->payment_status === 'paid') {
                $this->checkoutService->refundRazorpayPayment($order, 'order_returned');
                $order->payment_status = 'refunded';
            }

            $this->restoreStockForOrder($order);

            $order->markReturned();

            if ($request->input('reason')) {
                $order->notes = ($order->notes ? $order->notes . "\n\n" : '') .
                               "Return reason: " . $request->input('reason');
            }

            $order->save();

            DB::commit();
            $transactionStarted = false;

            return response()->json([
                'status' => true,
                'data' => new OrderResource($order->load(['orderItems.product.images', 'orderItems.productSize', 'shipment'])),
                'message' => $order->payment_status === 'refunded'
                    ? 'Return pickup created and payment refunded successfully'
                    : 'Return pickup created successfully',
            ]);

        } catch (DomainException $e) {
            if ($transactionStarted) {
                DB::rollBack();
            }

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 400);

        } catch (\Exception $e) {
            if ($transactionStarted) {
                DB::rollBack();
            }

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
