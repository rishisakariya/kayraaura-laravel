<?php

namespace App\Jobs;

use App\Models\DelhiverySetting;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Services\Delhivery\DelhiveryShipmentService;
use App\Services\Shiprocket\ShiprocketShipmentService;
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
        DelhiveryShipmentService $shipmentService,
        ShiprocketShipmentService $shiprocketService
    ): void
    {
        $order = Order::findOrFail($this->orderId);

        try {
            $shipmentService->createShipment($order);
            return;
        } catch (\Throwable $e) {
            if (!config('shiprocket.fallback_enabled') || !$shiprocketService->isConfigured()) {
                throw $e;
            }

            $shiprocketService->createShipment($order);
        }
    }

    public function failed(Throwable $exception): void
    {
        $shipment = OrderShipment::where('order_id', $this->orderId)->first();

        $provider = $shipment?->provider ?? OrderShipment::PROVIDER_DELHIVERY;
        $pickupLocation = $provider === OrderShipment::PROVIDER_SHIPROCKET
            ? (string) config('shiprocket.pickup_location')
            : DelhiverySetting::current()->pickup_location;

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
