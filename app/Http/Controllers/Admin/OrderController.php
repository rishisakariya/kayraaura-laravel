<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderReturnRefundRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderReturnService;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderReturnService $orderReturnService,
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
     * Process Razorpay refund for a return received at the warehouse (online orders only).
     */
    public function payReturnRefund(OrderReturnRefundRequest $request, string $id): JsonResponse
    {
        try {
            $order = Order::findOrFail($id);

            if (!$this->orderReturnService->canPayReturnRefund($order)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This order does not have a return refund ready to process',
                ], 400);
            }

            $result = $this->orderReturnService->processOnlineReturnRefund(
                $order,
                $request->validated('return_request_id')
            );

            $order->refresh()->load(['user', 'orderItems.product.images', 'orderItems.productSize', 'shipment.statusHistories']);

            return response()->json([
                'success' => true,
                'message' => 'Refund of ₹'
                    . number_format($result['refund_amount'], 2)
                    . ' has been processed successfully',
                'data' => [
                    'order' => new OrderResource($order),
                    'refund_amount' => $result['refund_amount'],
                    'return_request_id' => $result['return_request_id'],
                ],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        } catch (DomainException $e) {
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
