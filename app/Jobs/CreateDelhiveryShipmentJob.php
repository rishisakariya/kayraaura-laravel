<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\ShipmentStatusHistory;
use App\Services\Delhivery\DelhiveryShipmentService;
use App\Services\Shipping\ShippingProviderResolver;
use App\Services\Shiprocket\ShiprocketShipmentService;
use DomainException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateDelhiveryShipmentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $orderId)
    {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        DelhiveryShipmentService $delhiveryService,
        ShiprocketShipmentService $shiprocketService,
        ShippingProviderResolver $providerResolver
    ): void
    {
        Log::info('Delhivery job: create shipment started', [
            'order_id' => $this->orderId,
            'attempt' => $this->attempts(),
        ]);

        $order = Order::findOrFail($this->orderId);
        $provider = $providerResolver->activeProvider();

        if ($provider === OrderShipment::PROVIDER_SHIPROCKET) {
            if (!$shiprocketService->isConfigured()) {
                throw new DomainException('Shiprocket is enabled but credentials are not configured.');
            }

            $shiprocketService->createShipment($order);

            Log::info('Delhivery job: create shipment completed via Shiprocket', [
                'order_id' => $this->orderId,
            ]);

            return;
        }

        if (!$delhiveryService->isConfigured()) {
            throw new DomainException('Delhivery is enabled but API token is not configured.');
        }

        $shipment = $delhiveryService->createShipment($order);

        Log::info('Delhivery job: create shipment completed', [
            'order_id' => $this->orderId,
            'shipment_id' => $shipment->id,
            'waybill' => $shipment->waybill,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Delhivery job: create shipment failed', [
            'order_id' => $this->orderId,
            'attempt' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);

        $delhiveryService = app(DelhiveryShipmentService::class);

        try {
            if ($delhiveryService->reconcileOrderShipment($this->orderId)) {
                return;
            }
        } catch (Throwable) {
            // Continue to failed-state handling below.
        }

        $shipment = OrderShipment::where('order_id', $this->orderId)->first();

        if ($shipment?->hasWaybill()) {
            try {
                $delhiveryService->reconcileOrderShipment($this->orderId);
            } catch (Throwable) {
                // Keep existing shipment record as-is.
            }

            return;
        }

        if (!$shipment || $shipment->shipment_status === OrderShipment::STATUS_FAILED) {
            return;
        }

        if ($shipment->shipment_status !== OrderShipment::STATUS_RETRY_PENDING) {
            return;
        }

        try {
            $provider = $shipment->provider ?? app(ShippingProviderResolver::class)->activeProvider();
            $pickupLocation = app(ShippingProviderResolver::class)->pickupLocation();
        } catch (Throwable) {
            $provider = $shipment->provider ?? OrderShipment::PROVIDER_DELHIVERY;
            $pickupLocation = $shipment->pickup_location;
        }

        $shipment->withAuditSource(ShipmentStatusHistory::SOURCE_SYSTEM)->fill([
            'provider' => $provider,
            'shipment_status' => OrderShipment::STATUS_FAILED,
            'failed_reason' => $exception->getMessage(),
            'pickup_location' => $pickupLocation,
        ])->save();
    }
}
