<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Jobs\CancelDelhiveryShipmentJob;
use App\Jobs\CreateDelhiveryShipmentJob;
use App\Jobs\SyncDelhiveryShipmentStatusJob;
use App\Models\DelhiverySetting;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Services\Delhivery\DelhiveryShipmentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OrderShipmentController extends Controller
{
    public function __construct(private readonly DelhiveryShipmentService $shipmentService)
    {
    }

    public function create(string $id): JsonResponse
    {
        $order = Order::with(['shipment', 'orderItems.product.images', 'orderItems.productSize'])
            ->findOrFail($id);

        if ($order->payment_method === 'online' && $order->payment_status !== 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Online order payment must be verified before shipment creation',
            ], 409);
        }

        $shipment = DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->payment_method === 'cod' && $order->status === 'pending_admin_confirmation') {
                $order->forceFill(['status' => 'processing'])->save();
            }

            $shipment = OrderShipment::firstOrCreate(
                ['order_id' => $order->id],
                [
                    'provider' => OrderShipment::PROVIDER_DELHIVERY,
                    'shipment_status' => OrderShipment::STATUS_NOT_CREATED,
                    'pickup_location' => DelhiverySetting::current()->pickup_location,
                ]
            );

            if (!$shipment->waybill) {
                $shipment->forceFill([
                    'shipment_status' => OrderShipment::STATUS_NOT_CREATED,
                    'failed_reason' => null,
                ])->save();
            }

            return $shipment;
        });

        if (!$shipment->waybill) {
            CreateDelhiveryShipmentJob::dispatch((int) $id);
        }

        return response()->json([
            'success' => true,
            'data' => new OrderResource($order->refresh()->load(['shipment', 'orderItems.product.images', 'orderItems.productSize'])),
            'message' => $shipment->waybill
                ? 'Shipment already exists for this order'
                : 'Shipment creation queued',
        ]);
    }

    public function sync(string $id): JsonResponse
    {
        $order = Order::with(['shipment', 'orderItems.product.images', 'orderItems.productSize'])
            ->findOrFail($id);

        if (!$order->shipment?->waybill) {
            return response()->json([
                'success' => false,
                'message' => 'Shipment AWB is not available yet',
            ], 409);
        }

        if (!in_array($order->shipment->shipment_status, OrderShipment::TERMINAL_STATUSES, true)) {
            SyncDelhiveryShipmentStatusJob::dispatch($order->shipment->id);
        }

        return response()->json([
            'success' => true,
            'data' => new OrderResource($order->refresh()->load(['shipment', 'orderItems.product.images', 'orderItems.productSize'])),
            'message' => 'Shipment sync queued',
        ]);
    }

    public function cancel(string $id): JsonResponse
    {
        $order = Order::with(['shipment', 'orderItems.product.images', 'orderItems.productSize'])
            ->findOrFail($id);

        if (!$order->shipment?->waybill) {
            return response()->json([
                'success' => false,
                'message' => 'Shipment AWB is not available yet',
            ], 409);
        }

        if (in_array($order->shipment->shipment_status, [
            OrderShipment::STATUS_DELIVERED,
            OrderShipment::STATUS_CANCELLED,
            OrderShipment::STATUS_RTO,
        ], true)) {
            return response()->json([
                'success' => true,
                'data' => new OrderResource($order),
                'message' => 'Shipment is already in a terminal state',
            ]);
        }

        CancelDelhiveryShipmentJob::dispatch($order->shipment->id);

        return response()->json([
            'success' => true,
            'data' => new OrderResource($order->refresh()->load(['shipment', 'orderItems.product.images', 'orderItems.productSize'])),
            'message' => 'Shipment cancellation queued',
        ]);
    }

    public function label(string $id): JsonResponse
    {
        $order = Order::with('shipment')->findOrFail($id);

        if (!$order->shipment?->waybill) {
            if (!app()->isProduction()) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'shipping_label_url' => $this->shipmentService->generateTestShippingLabel($order),
                        'is_test_label' => true,
                    ],
                    'message' => 'Test shipment label generated because AWB is not available yet',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Shipment AWB is not available yet',
            ], 409);
        }

        try {
            $shipment = $this->shipmentService->generateShippingLabel($order->shipment);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'shipping_label_url' => $shipment->shipping_label_url,
            ],
        ]);
    }
}
