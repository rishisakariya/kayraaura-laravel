<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\SyncDelhiveryShipmentStatusJob;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Services\Delhivery\DelhiveryShipmentService;
use App\Services\Shipping\ShippingProviderResolver;
use App\Services\Shiprocket\ShiprocketShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderShipmentController extends Controller
{
    public function __construct(
        private readonly DelhiveryShipmentService $delhiveryShipmentService,
        private readonly ShiprocketShipmentService $shiprocketShipmentService,
        private readonly ShippingProviderResolver $shippingProviderResolver
    )
    {
    }

    public function show(string $id): JsonResponse
    {
        $order = Order::where('user_id', Auth::id())
            ->with('shipment.statusHistories')
            ->findOrFail($id);

        return response()->json([
            'status' => true,
            'data' => $order->shipment
                ? $this->trackingResponse($order->shipment)
                : $this->notCreatedPayload(),
        ]);
    }

    public function track(Request $request): JsonResponse
    {
        $request->validate([
            'order_number' => 'nullable|string|required_without:awb',
            'awb' => 'nullable|string|required_without:order_number',
        ]);

        $query = Order::where('user_id', Auth::id())->with('shipment.statusHistories');

        if ($request->filled('order_number')) {
            $order = $query->where('order_number', $request->query('order_number'))->firstOrFail();
        } else {
            $order = $query->whereHas('shipment', function ($query) use ($request) {
                $query->where('waybill', $request->query('awb'));
            })->firstOrFail();
        }

        return response()->json([
            'status' => true,
            'data' => $order->shipment
                ? $this->trackingResponse($order->shipment)
                : $this->notCreatedPayload(),
        ]);
    }

    public function refresh(string $id): JsonResponse
    {
        $order = Order::where('user_id', Auth::id())
            ->with('shipment.statusHistories')
            ->findOrFail($id);

        $shipment = $order->shipment;

        if (!$shipment) {
            return response()->json([
                'status' => true,
                'data' => $this->notCreatedPayload(),
                'message' => 'Shipment is not created yet',
            ]);
        }

        $cacheMinutes = $shipment->provider === OrderShipment::PROVIDER_SHIPROCKET
            ? (int) config('shiprocket.sync_cache_minutes', 15)
            : (int) config('delhivery.sync_cache_minutes', 15);

        if (
            $shipment->waybill
            && (!$shipment->last_synced_at || $shipment->last_synced_at->lt(now()->subMinutes($cacheMinutes)))
            && !in_array($shipment->shipment_status, OrderShipment::TERMINAL_STATUSES, true)
        ) {
            SyncDelhiveryShipmentStatusJob::dispatch($shipment->id);
        }

        return response()->json([
            'status' => true,
            'data' => $this->trackingResponse($shipment->refresh()),
            'message' => 'Tracking refresh queued',
        ]);
    }

    private function notCreatedPayload(): array
    {
        return [
            'provider' => $this->shippingProviderResolver->activeProvider(),
            'waybill' => null,
            'shipment_status' => OrderShipment::STATUS_NOT_CREATED,
            'raw_status' => null,
            'status_location' => null,
            'status_instructions' => null,
            'courier_tracking_url' => null,
            'last_synced_at' => null,
            'tracking' => [],
        ];
    }

    private function trackingResponse(OrderShipment $shipment): array
    {
        return $this->shipmentServiceForProvider($shipment)->trackingData($shipment);
    }

    private function shipmentServiceForProvider(OrderShipment $shipment): DelhiveryShipmentService|ShiprocketShipmentService
    {
        return $shipment->provider === OrderShipment::PROVIDER_SHIPROCKET
            ? $this->shiprocketShipmentService
            : $this->delhiveryShipmentService;
    }
}
