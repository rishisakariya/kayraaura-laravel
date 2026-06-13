<?php

namespace App\Jobs;

use App\Models\DelhiverySetting;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Services\Delhivery\DelhiveryShipmentService;
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

    public function handle(DelhiveryShipmentService $shipmentService): void
    {
        $order = Order::findOrFail($this->orderId);

        $shipmentService->createShipment($order);
    }

    public function failed(Throwable $exception): void
    {
        OrderShipment::updateOrCreate(
            ['order_id' => $this->orderId],
            [
                'provider' => OrderShipment::PROVIDER_DELHIVERY,
                'shipment_status' => OrderShipment::STATUS_FAILED,
                'failed_reason' => $exception->getMessage(),
                'pickup_location' => DelhiverySetting::current()->pickup_location,
            ]
        );
    }
}
