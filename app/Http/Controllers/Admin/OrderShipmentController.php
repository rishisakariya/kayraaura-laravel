<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Jobs\CancelDelhiveryShipmentJob;
use App\Jobs\CreateDelhiveryShipmentJob;
use App\Jobs\SyncDelhiveryShipmentStatusJob;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\ShipmentStatusHistory;
use App\Services\Delhivery\DelhiveryShipmentService;
use App\Services\Shipping\ShippingProviderResolver;
use App\Services\Shiprocket\ShiprocketShipmentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OrderShipmentController extends Controller
{
    public function __construct(
        private readonly DelhiveryShipmentService $delhiveryShipmentService,
        private readonly ShiprocketShipmentService $shiprocketShipmentService,
        private readonly ShippingProviderResolver $shippingProviderResolver
    )
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

            $provider = $this->shippingProviderResolver->activeProvider();

            $shipment = OrderShipment::firstOrCreate(
                ['order_id' => $order->id],
                [
                    'provider' => $provider,
                    'shipment_status' => OrderShipment::STATUS_NOT_CREATED,
                    'pickup_location' => $this->shippingProviderResolver->pickupLocation(),
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

        CancelDelhiveryShipmentJob::dispatch($order->shipment->id, ShipmentStatusHistory::SOURCE_ADMIN);

        return response()->json([
            'success' => true,
            'data' => new OrderResource($order->refresh()->load(['shipment.statusHistories', 'orderItems.product.images', 'orderItems.productSize'])),
            'message' => 'Shipment cancellation queued',
        ]);
    }

    public function label(string $id): JsonResponse
    {
        $order = Order::with('shipment')->findOrFail($id);

        try {
            $label = $this->labelPayload($order);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() === 409 ? 409 : 422);
        }

        return response()->json([
            'success' => true,
            'data' => $label['data'],
            'message' => $label['message'] ?? null,
        ]);
    }

    public function downloadLabel(string $id): BinaryFileResponse|JsonResponse
    {
        $order = Order::with('shipment')->findOrFail($id);

        try {
            $label = $this->labelPayload($order);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() === 409 ? 409 : 422);
        }

        $path = $this->publicStoragePathFromUrl($label['data']['shipping_label_url']);

        if (!$path || !Storage::disk('public')->exists($path)) {
            return response()->json([
                'success' => false,
                'message' => 'Shipment label PDF file was not found',
            ], 404);
        }

        return response()->download(
            Storage::disk('public')->path($path),
            basename($path),
            ['Content-Type' => 'application/pdf']
        );
    }

    public function bulkLabels(Request $request): JsonResponse|\Symfony\Component\HttpFoundation\Response
    {
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:30'],
            'order_ids.*' => ['required', 'integer', 'distinct', 'exists:orders,id'],
        ]);

        $orders = Order::with('shipment')
            ->whereIn('id', $validated['order_ids'])
            ->get()
            ->keyBy('id');

        $orderedOrders = collect($validated['order_ids'])
            ->map(fn (int $orderId) => $orders->get($orderId))
            ->filter();

        $ordersWithoutShipment = $orderedOrders->filter(fn (Order $order) => !$order->shipment);

        if ($ordersWithoutShipment->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Some selected orders do not have a shipment yet',
                'data' => [
                    'order_ids' => $ordersWithoutShipment->pluck('id')->values()->all(),
                ],
            ], 422);
        }

        $ordersWithoutAwb = $orderedOrders->filter(fn (Order $order) => !$order->shipment?->hasWaybill());

        if ($ordersWithoutAwb->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Some selected orders do not have a shipment AWB yet',
                'data' => [
                    'order_ids' => $ordersWithoutAwb->pluck('id')->values()->all(),
                ],
            ], 422);
        }

        $nonDelhiveryOrders = $orderedOrders->filter(
            fn (Order $order) => $order->shipment->provider !== OrderShipment::PROVIDER_DELHIVERY
        );

        if ($nonDelhiveryOrders->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Bulk Delhivery labels can only be generated for Delhivery shipments',
                'data' => [
                    'order_ids' => $nonDelhiveryOrders->pluck('id')->values()->all(),
                ],
            ], 422);
        }

        try {
            $pdfBinary = $this->delhiveryShipmentService->generateMergedShippingLabels(
                $orderedOrders->pluck('shipment')
            );
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate Delhivery labels. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        $filename = 'delhivery-labels-' . now()->format('Ymd-His') . '.pdf';

        return response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function labelPayload(Order $order): array
    {
        if (!$order->shipment?->waybill) {
            throw new DomainException('Shipment AWB is not available yet', 409);
        }

        $shipment = $order->shipment;
        $shipmentService = $this->shipmentServiceForProvider($shipment);
        $shipment = $shipmentService->generateShippingLabel($shipment);

        return [
            'data' => [
                'shipping_label_url' => $shipment->shipping_label_url,
                'download_label_url' => $this->downloadLabelUrl($order),
                'source' => $shipment->provider === OrderShipment::PROVIDER_DELHIVERY ? 'delhivery' : 'shiprocket',
            ],
        ];
    }

    private function shipmentServiceForProvider(OrderShipment $shipment): DelhiveryShipmentService|ShiprocketShipmentService
    {
        return $shipment->provider === OrderShipment::PROVIDER_SHIPROCKET
            ? $this->shiprocketShipmentService
            : $this->delhiveryShipmentService;
    }

    private function downloadLabelUrl(Order $order): string
    {
        return URL::temporarySignedRoute(
            'admin.orders.shipment.label.download',
            now()->addMinutes(30),
            ['id' => $order->id]
        );
    }

    private function publicStoragePathFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (!is_string($path) || !str_starts_with($path, '/storage/')) {
            return null;
        }

        return urldecode(substr($path, strlen('/storage/')));
    }
}
