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
use App\Services\OrderCancellationService;
use App\Services\Shipping\ShippingProviderResolver;
use App\Services\Shiprocket\ShiprocketShipmentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Support\PublicStorage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OrderShipmentController extends Controller
{
    public function __construct(
        private readonly DelhiveryShipmentService $delhiveryShipmentService,
        private readonly ShiprocketShipmentService $shiprocketShipmentService,
        private readonly ShippingProviderResolver $shippingProviderResolver,
        private readonly OrderCancellationService $orderCancellationService,
    )
    {
    }

    public function create(string $id): JsonResponse
    {
        Log::channel('thirdparty')->info('Delhivery flow: admin shipment create requested', [
            'order_id' => $id,
        ]);

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

            Log::channel('thirdparty')->info('Delhivery flow: admin shipment create job dispatched', [
                'order_id' => $id,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => new OrderResource($order->refresh()->load(['shipment', 'orderItems.product.images', 'orderItems.productSize'])),
            'message' => $shipment->waybill
                ? 'Shipment already exists for this order'
                : 'Shipment creation queued',
        ]);
    }

    public function retryShipment(string $id): JsonResponse
    {
        Log::channel('thirdparty')->info('Delhivery flow: admin retry shipment requested', [
            'order_id' => $id,
        ]);

        $order = Order::with(['shipment', 'orderItems.product.images', 'orderItems.productSize'])
            ->findOrFail($id);

        if ($order->payment_method === 'online' && $order->payment_status !== 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Online order payment must be verified before shipment retry',
            ], 409);
        }

        if ($order->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Cancelled orders cannot be shipped',
            ], 409);
        }

        $shipment = $order->shipment;

        if ($shipment?->hasWaybill()) {
            try {
                if ($shipment->provider === OrderShipment::PROVIDER_SHIPROCKET) {
                    // Keep Shiprocket AWB as-is.
                } else {
                    $this->delhiveryShipmentService->reconcileOrderShipment($order);
                }
            } catch (\Throwable) {
                // Ignore reconcile errors when AWB already exists locally.
            }

            return response()->json([
                'success' => true,
                'data' => new OrderResource($order->refresh()->load(['shipment', 'orderItems.product.images', 'orderItems.productSize'])),
                'message' => 'Shipment already exists for this order',
            ]);
        }

        $status = $shipment?->shipment_status ?? OrderShipment::STATUS_NOT_CREATED;

        if (!in_array($status, [
            OrderShipment::STATUS_CREATION_FAILED,
            OrderShipment::STATUS_FAILED,
            OrderShipment::STATUS_RETRY_PENDING,
            OrderShipment::STATUS_NOT_CREATED,
        ], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Shipment retry is only allowed when creation failed or is not created yet',
            ], 409);
        }

        // Recover AWB from Delhivery before creating again.
        if (($shipment?->provider ?? OrderShipment::PROVIDER_DELHIVERY) === OrderShipment::PROVIDER_DELHIVERY) {
            try {
                if ($this->delhiveryShipmentService->reconcileOrderShipment($order)) {
                    return response()->json([
                        'success' => true,
                        'data' => new OrderResource($order->refresh()->load(['shipment', 'orderItems.product.images', 'orderItems.productSize'])),
                        'message' => 'Existing Delhivery AWB recovered; shipment marked manifested',
                    ]);
                }
            } catch (\Throwable $e) {
                Log::channel('thirdparty')->warning('Delhivery flow: retry shipment AWB recovery failed', [
                    'order_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $provider = $this->shippingProviderResolver->activeProvider();

            $shipment = OrderShipment::firstOrCreate(
                ['order_id' => $order->id],
                [
                    'provider' => $provider,
                    'shipment_status' => OrderShipment::STATUS_RETRY_PENDING,
                    'pickup_location' => $this->shippingProviderResolver->pickupLocation(),
                ]
            );

            $shipment->withAuditSource(ShipmentStatusHistory::SOURCE_ADMIN)->forceFill([
                'provider' => $provider,
                'shipment_status' => OrderShipment::STATUS_RETRY_PENDING,
                'failed_reason' => null,
                'pickup_location' => $this->shippingProviderResolver->pickupLocation(),
            ])->save();
        });

        CreateDelhiveryShipmentJob::dispatch((int) $id);

        Log::channel('thirdparty')->info('Delhivery flow: admin retry shipment job dispatched', [
            'order_id' => $id,
        ]);

        return response()->json([
            'success' => true,
            'data' => new OrderResource($order->refresh()->load(['shipment', 'orderItems.product.images', 'orderItems.productSize'])),
            'message' => 'Shipment retry queued',
        ]);
    }

    public function sync(string $id): JsonResponse
    {
        Log::channel('thirdparty')->info('Delhivery flow: admin shipment sync requested', [
            'order_id' => $id,
        ]);

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

            Log::channel('thirdparty')->info('Delhivery flow: admin shipment sync job dispatched', [
                'order_id' => $id,
                'shipment_id' => $order->shipment->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => new OrderResource($order->refresh()->load(['shipment', 'orderItems.product.images', 'orderItems.productSize'])),
            'message' => 'Shipment sync queued',
        ]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

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

        try {
            Log::channel('thirdparty')->info('Delhivery flow: admin order cancel requested', [
                'order_id' => $id,
                'reason' => $request->input('reason'),
            ]);

            $shipmentToCancel = $this->orderCancellationService->cancel(
                $order,
                $request->input('reason'),
                'admin',
            );

            if ($shipmentToCancel) {
                CancelDelhiveryShipmentJob::dispatch($shipmentToCancel->id, ShipmentStatusHistory::SOURCE_ADMIN);

                Log::channel('thirdparty')->info('Delhivery flow: admin cancel shipment job dispatched', [
                    'order_id' => $id,
                    'shipment_id' => $shipmentToCancel->id,
                ]);
            }

            $order->refresh()->load(['shipment.statusHistories', 'orderItems.product.images', 'orderItems.productSize']);

            $message = match (true) {
                $order->payment_method === 'online' && $order->payment_status === 'refunded'
                    => 'Order cancelled, refund processed, and shipment cancellation queued',
                $order->payment_method === 'online' && $order->payment_status === 'refund_processing'
                    => 'Order cancelled, refund initiated, and shipment cancellation queued',
                default => 'Order cancelled and shipment cancellation queued',
            };

            return response()->json([
                'success' => true,
                'data' => new OrderResource($order),
                'message' => $message,
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel order. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function label(Request $request, string $id): JsonResponse
    {
        $this->normalizeDownloadedFlag($request);

        $request->validate([
            'is_downloaded' => ['sometimes', 'boolean'],
        ]);

        $order = Order::with('shipment')->findOrFail($id);

        try {
            $label = $this->labelPayload($order);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() === 409 ? 409 : 422);
        }

        $this->syncLabelDownloadedStatus($order->shipment, $request);

        return response()->json([
            'success' => true,
            'data' => array_merge($label['data'], [
                'is_downloaded' => (bool) $order->shipment?->fresh()?->is_downloaded,
            ]),
            'message' => $label['message'] ?? null,
        ]);
    }

    public function downloadLabel(Request $request, string $id): BinaryFileResponse|JsonResponse
    {
        $this->normalizeDownloadedFlag($request);

        $request->validate([
            'is_downloaded' => ['sometimes', 'boolean'],
        ]);

        $order = Order::with('shipment')->findOrFail($id);

        try {
            $label = $this->labelPayload($order);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() === 409 ? 409 : 422);
        }

        $path = PublicStorage::diskPath($label['data']['shipping_label_url']);

        if (!$path || !PublicStorage::exists($path)) {
            return response()->json([
                'success' => false,
                'message' => 'Shipment label PDF file was not found',
            ], 404);
        }

        $this->syncLabelDownloadedStatus($order->shipment, $request);

        return response()->download(
            PublicStorage::absolutePath($path),
            basename($path),
            ['Content-Type' => 'application/pdf']
        );
    }

    public function bulkLabels(Request $request): JsonResponse|\Symfony\Component\HttpFoundation\Response
    {
        $this->normalizeDownloadedFlag($request);

        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:30'],
            'order_ids.*' => ['required', 'integer', 'distinct', 'exists:orders,id'],
            'is_downloaded' => ['sometimes', 'boolean'],
        ]);

        $labelsPerPage = 4;

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

        $cancelledShipments = $orderedOrders->filter(
            fn (Order $order) => $order->shipment->shipment_status === OrderShipment::STATUS_CANCELLED
        );

        if ($cancelledShipments->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cancelled shipments cannot be included in bulk label download',
                'data' => [
                    'order_ids' => $cancelledShipments->pluck('id')->values()->all(),
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
                $orderedOrders->pluck('shipment'),
                $labelsPerPage
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

        if ($request->has('is_downloaded')) {
            OrderShipment::whereIn('order_id', $orderedOrders->pluck('id'))
                ->update(['is_downloaded' => $request->boolean('is_downloaded')]);
        }

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

    private function normalizeDownloadedFlag(Request $request): void
    {
        if ($request->has('is_downloaded')) {
            $request->merge([
                'is_downloaded' => $request->boolean('is_downloaded'),
            ]);
        }
    }

    private function syncLabelDownloadedStatus(?OrderShipment $shipment, Request $request): void
    {
        if (!$request->has('is_downloaded') || !$shipment) {
            return;
        }

        $shipment->update([
            'is_downloaded' => $request->boolean('is_downloaded'),
        ]);
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
        return PublicStorage::diskPath($url);
    }
}
