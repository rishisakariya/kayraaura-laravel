<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderStoreRequest;
use App\Http\Requests\OrderCancelRequest;
use App\Http\Requests\OrderReturnPreviewRequest;
use App\Http\Requests\OrderReturnRequest;
use App\Http\Resources\OrderResource;
use App\Jobs\CancelDelhiveryShipmentJob;
use App\Jobs\CreateDelhiveryShipmentJob;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\ProductSize;
use App\Models\UserAddress;
use App\Models\WebSetting;
use App\Services\CheckoutService;
use App\Services\Delhivery\DelhiveryShipmentService;
use App\Services\OrderCancellationService;
use App\Services\OrderReturnService;
use App\Services\OtpService;
use App\Services\ScratchCardService;
use App\Services\Shiprocket\ShiprocketShipmentService;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Support\PublicStorage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OrderController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly DelhiveryShipmentService $shipmentService,
        private readonly ShiprocketShipmentService $shiprocketShipmentService,
        private readonly OtpService $otpService,
        private readonly ScratchCardService $scratchCardService,
        private readonly OrderReturnService $orderReturnService,
        private readonly OrderCancellationService $orderCancellationService,
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

            if ($order->payment_method === 'cod') {
                CreateDelhiveryShipmentJob::dispatch($order->id);

                Log::info('Order flow: COD order placed, shipment job dispatched', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);
            }

            Log::info('Order flow: order placed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_method' => $order->payment_method,
                'total_amount' => $order->total_amount,
            ]);

            return response()->json([
                'status' => true,
                'data' => [
                    'order' => new OrderResource($order),
                    'razorpay' => $razorpayCheckout,
                ],
                'message' => $order->payment_method === 'cod'
                    ? 'Order placed successfully'
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
     * Return an invoice download URL for a successful order.
     */
    public function invoice(string $id): JsonResponse
    {
        $order = $this->findCustomerOrderForInvoice($id);

        if ($order instanceof JsonResponse) {
            return $order;
        }

        return response()->json([
            'status' => true,
            'data' => [
                'invoice_download_url' => $this->invoiceDownloadUrl($order),
            ],
            'message' => 'Invoice download URL generated successfully',
        ]);
    }

    /**
     * Download the invoice PDF file (public — no auth required).
     */
    public function downloadInvoice(string $id): BinaryFileResponse|JsonResponse
    {
        $order = Order::with(['user', 'orderItems.productSize'])->find($id);

        if (!$order) {
            return response()->json([
                'status' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        if (!$this->canDownloadInvoice($order)) {
            return response()->json([
                'status' => false,
                'message' => 'Invoice is available only after the order is placed successfully.',
            ], 409);
        }

        $path = $this->generateInvoiceFile($order);
        $fileName = $this->invoiceFileName($order);

        return response()->download(
            PublicStorage::absolutePath($path),
            $fileName,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            ]
        );
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
                'message' => $order->cancellationBlockReason()
                    ?? 'Order cannot be cancelled after shipment pickup or while in transit',
            ], 400);
        }

        try {
            Log::info('Order cancellation flow: API request received', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'user_id' => $order->user_id,
            ]);

            $shipmentToCancel = $this->orderCancellationService->cancel(
                $order,
                $request->input('reason'),
            );

            if ($shipmentToCancel) {
                CancelDelhiveryShipmentJob::dispatch($shipmentToCancel->id);

                Log::info('Order cancellation flow: cancel shipment job dispatched', [
                    'order_id' => $order->id,
                    'shipment_id' => $shipmentToCancel->id,
                ]);
            }

            return response()->json([
                'status' => true,
                'data' => new OrderResource($order->refresh()->load(['orderItems.product.images', 'orderItems.productSize', 'shipment'])),
                'message' => 'Order cancelled successfully',
            ]);

        } catch (DomainException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to cancel order. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Preview refundable amount for a selected item-level return.
     */
    public function previewReturn(OrderReturnPreviewRequest $request, string $id): JsonResponse
    {
        $order = Order::where('user_id', Auth::id())
            ->with('orderItems')
            ->findOrFail($id);

        if (!$order->canBeReturned()) {
            return response()->json([
                'status' => false,
                'message' => 'This order is not eligible for return',
            ], 400);
        }

        $refundCalculation = $this->orderReturnService->calculateRefund(
            $order,
            $request->validated('items')
        );

        return response()->json([
            'status' => true,
            'data' => [
                'items' => $refundCalculation['items'],
                'refund_amount' => $refundCalculation['refund_amount'],
                'is_partial' => $refundCalculation['is_partial'],
                'return_summary' => $this->orderReturnService->buildOrderReturnSummary($order),
            ],
        ]);
    }

    /**
     * Return a delivered order and schedule reverse pickup.
     */
    public function returnOrder(OrderReturnRequest $request, string $id): JsonResponse
    {
        $order = Order::where('user_id', Auth::id())
            ->with(['shipment', 'orderItems.product'])
            ->findOrFail($id);

        if (!$order->canBeReturned()) {
            return response()->json([
                'status' => false,
                'message' => 'Only delivered orders with returnable items can be returned',
            ], 400);
        }

        $deliveredAt = $order->delivered_at ?? $order->shipment?->delivered_at;

        if (!$deliveredAt) {
            return response()->json([
                'status' => false,
                'message' => 'Delivery date is not available for this order',
            ], 400);
        }

        if (now()->gt($deliveredAt->copy()->addDays(Order::RETURN_WINDOW_DAYS))) {
            return response()->json([
                'status' => false,
                'message' => 'Return window expired. Returns are allowed within '
                    . Order::RETURN_WINDOW_DAYS
                    . ' days from delivery',
            ], 400);
        }

        try {
            Log::info('Return flow: return request received', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_method' => $order->payment_method,
            ]);

            $service = $order->shipment?->provider === OrderShipment::PROVIDER_SHIPROCKET
                ? $this->shiprocketShipmentService
                : $this->shipmentService;

            $validated = $request->validated();
            $refundCalculation = $this->orderReturnService->calculateRefund(
                $order,
                $validated['items']
            );
            $imageUrls = $this->orderReturnService->storeProductImages(
                $order,
                $request->file('product_images', [])
            );

            try {
                $service->createReversePickup($order);
                $returnRequest = $this->orderReturnService->buildReturnRequestPayload(
                    $order,
                    $validated,
                    $imageUrls,
                    $refundCalculation
                );
                $order->refresh()->markReturnRequested($returnRequest);
            } catch (\Throwable $e) {
                $this->orderReturnService->deleteProductImages($imageUrls);

                Log::error('Return flow: reverse pickup or return request failed', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            Log::info('Return flow: return request accepted', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'refund_amount' => $refundCalculation['refund_amount'],
                'is_partial' => $refundCalculation['is_partial'],
            ]);

            $message = $order->payment_method === 'cod'
                ? 'Return pickup scheduled successfully. A refund of ₹'
                    . number_format($refundCalculation['refund_amount'], 2)
                    . ' will be processed to your UPI after the returned products are received.'
                : 'Return pickup scheduled successfully. A refund of ₹'
                    . number_format($refundCalculation['refund_amount'], 2)
                    . ' will be processed after the returned products are received.';

            return response()->json([
                'status' => true,
                'data' => new OrderResource($order->load(['orderItems.product.images', 'orderItems.productSize', 'shipment'])),
                'message' => $message,
            ]);

        } catch (DomainException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to return order. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function restoreStockForOrder(Order $order): void
    {
        $this->checkoutService->restoreStockForOrder($order);
    }

    private function findCustomerOrderForInvoice(string $id): Order|JsonResponse
    {
        $order = Order::where('user_id', Auth::id())->find($id);

        if (!$order) {
            return response()->json([
                'status' => false,
                'message' => 'Order not found for the authenticated customer.',
            ], 404);
        }

        if (!$this->canDownloadInvoice($order)) {
            return response()->json([
                'status' => false,
                'message' => 'Invoice is available only after the order is placed successfully.',
            ], 409);
        }

        return $order;
    }

    private function invoiceDownloadUrl(Order $order): string
    {
        return route('orders.invoice.download', ['id' => $order->id]);
    }

    private function invoiceFileName(Order $order): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '-', $order->order_number) . '-invoice.pdf';
    }

    private function generateInvoiceFile(Order $order): string
    {
        $path = 'invoices/' . $order->id . '/' . $this->invoiceFileName($order);

        if (!PublicStorage::exists($path)) {
            $pdf = Pdf::loadView('orders.invoice', [
                'order' => $order,
                'webSetting' => WebSetting::current(),
                'invoiceNumber' => 'INV-' . $order->order_number,
            ])->setPaper('a4');

            PublicStorage::put($path, $pdf->output());
        }

        return $path;
    }

    private function canDownloadInvoice(Order $order): bool
    {
        if (in_array($order->status, ['cancelled'], true) || $order->payment_status === 'failed') {
            return false;
        }

        if ($order->payment_method === 'cod') {
            return (bool) $order->cod_verified_at;
        }

        return in_array($order->payment_status, ['paid', 'refunded'], true);
    }
}
