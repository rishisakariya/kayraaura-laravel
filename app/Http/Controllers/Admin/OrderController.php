<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderReturnRefundRequest;
use App\Http\Resources\Admin\OrderReturnEntryResource;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderReturnListingService;
use App\Services\OrderReturnService;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderReturnService $orderReturnService,
        private readonly OrderReturnListingService $orderReturnListingService,
    ) {
    }

    /**
     * Display a listing of orders for the admin panel.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['cod', 'online'])],
        ]);

        $orders = Order::with(['user', 'orderItems.product.images', 'orderItems.productSize', 'shipment'])
            ->where('payment_method', $validated['type'])
            ->when($validated['type'] === 'online', function ($query) {
                $query->where('payment_status', '!=', 'pending');
            })
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('order_number', 'like', "%{$search}%")
                        ->orWhere('razorpay_order_id', 'like', "%{$search}%")
                        ->orWhere('razorpay_payment_id', 'like', "%{$search}%");
                });
            })
            ->when($request->input('status'), function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($request->input('payment_status'), function ($query, $paymentStatus) {
                $query->where('payment_status', $paymentStatus);
            })
            ->when($request->input('shipping_status'), function ($query, $shippingStatus) {
                $query->whereHas('shipment', fn ($query) => $query->where('shipment_status', $shippingStatus));
            })
            ->when($request->input('shipping_provider'), function ($query, $provider) {
                $query->whereHas('shipment', fn ($query) => $query->where('provider', $provider));
            })
            ->when($request->input('waybill'), function ($query, $waybill) {
                $query->whereHas('shipment', fn ($query) => $query->where('waybill', 'like', "%{$waybill}%"));
            })
            ->when($request->input('shipment_created_from'), function ($query, $date) {
                $query->whereHas('shipment', fn ($query) => $query->whereDate('created_at', '>=', $date));
            })
            ->when($request->input('shipment_created_to'), function ($query, $date) {
                $query->whereHas('shipment', fn ($query) => $query->whereDate('created_at', '<=', $date));
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => OrderResource::collection($orders),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * List submitted return entries for online or COD orders.
     */
    public function returnEntries(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['cod', 'online'])],
            'status' => ['nullable', Rule::in(['pending', 'awaiting_refund', 'completed'])],
            'search' => ['nullable', 'string', 'max:255'],
            'requested_from' => ['nullable', 'date'],
            'requested_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $entries = $this->orderReturnListingService->paginate(
            $validated['type'],
            $validated
        );

        return response()->json([
            'success' => true,
            'data' => OrderReturnEntryResource::collection($entries),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
                'payment_method' => $validated['type'],
            ],
        ]);
    }

    /**
     * Display the specified order for the admin panel.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $order = Order::with(['user', 'orderItems.product.images', 'orderItems.productSize', 'shipment.statusHistories'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => new OrderResource($order),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }
    }

    /**
     * Process a return refund after the returned products are received at the warehouse.
     * Online orders are refunded via Razorpay; COD orders are paid to the customer's UPI ID.
     */
    public function payReturnRefund(OrderReturnRefundRequest $request, string $id): JsonResponse
    {
        try {
            $order = Order::findOrFail($id);

            Log::info('Return refund flow: admin payout requested', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_method' => $order->payment_method,
                'return_request_id' => $request->validated('return_request_id'),
            ]);

            if (!$this->orderReturnService->canPayReturnRefund($order)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This order does not have a return refund ready to process',
                ], 400);
            }

            $result = $this->orderReturnService->processReturnRefund(
                $order,
                $request->validated('return_request_id'),
                $request->validated('upi_transaction_reference'),
            );

            $order->refresh()->load(['user', 'orderItems.product.images', 'orderItems.productSize', 'shipment.statusHistories']);

            $message = ($result['payment_method'] ?? null) === 'cod'
                ? 'Refund of ₹'
                    . number_format($result['refund_amount'], 2)
                    . ' has been sent to UPI ID '
                    . ($result['upi_id'] ?? '')
                : 'Refund of ₹'
                    . number_format($result['refund_amount'], 2)
                    . ' has been processed successfully';

            Log::info('Return refund flow: admin payout completed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'refund_amount' => $result['refund_amount'],
                'return_request_id' => $result['return_request_id'],
                'payment_method' => $result['payment_method'] ?? $order->payment_method,
            ]);

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'order' => new OrderResource($order),
                    'refund_amount' => $result['refund_amount'],
                    'return_request_id' => $result['return_request_id'],
                    'payment_method' => $result['payment_method'] ?? $order->payment_method,
                    'upi_id' => $result['upi_id'] ?? null,
                ],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        } catch (DomainException $e) {
            Log::warning('Return refund flow: admin payout failed', [
                'order_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process return refund. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
