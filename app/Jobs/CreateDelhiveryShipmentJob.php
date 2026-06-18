<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderShipment;
use App\Services\Delhivery\DelhiveryShipmentService;
use App\Services\Shipping\ShippingProviderResolver;
use App\Services\Shiprocket\ShiprocketShipmentService;
use DomainException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        $order = Order::findOrFail($this->orderId);
        $provider = $providerResolver->activeProvider();

        if ($provider === OrderShipment::PROVIDER_SHIPROCKET) {
            if (!$shiprocketService->isConfigured()) {
                throw new DomainException('Shiprocket is enabled but credentials are not configured.');
            }

            $shiprocketService->createShipment($order);

            return;
        }

        if (!$delhiveryService->isConfigured()) {
            throw new DomainException('Delhivery is enabled but API token is not configured.');
        }

        $delhiveryService->createShipment($order);
    }

    public function failed(Throwable $exception): void
    {
        $shipment = OrderShipment::where('order_id', $this->orderId)->first();

        try {
            $provider = $shipment?->provider ?? app(ShippingProviderResolver::class)->activeProvider();
            $pickupLocation = app(ShippingProviderResolver::class)->pickupLocation();
        } catch (Throwable) {
            $provider = $shipment?->provider ?? OrderShipment::PROVIDER_DELHIVERY;
            $pickupLocation = $shipment?->pickup_location;
        }

        OrderShipment::updateOrCreate(
            ['order_id' => $this->orderId],
            [
                'provider' => $provider,
                'shipment_status' => OrderShipment::STATUS_FAILED,
                'failed_reason' => $exception->getMessage(),
                'pickup_location' => $pickupLocation,
            ]
        );
    }
}
