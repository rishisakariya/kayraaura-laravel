<?php

namespace App\Jobs;

use App\Models\OrderShipment;
use App\Models\ShipmentStatusHistory;
use App\Services\Delhivery\DelhiveryShipmentService;
use App\Services\Shiprocket\ShiprocketShipmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CancelDelhiveryShipmentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $shipmentId,
        public readonly string $auditSource = ShipmentStatusHistory::SOURCE_SYSTEM,
    )
    {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        DelhiveryShipmentService $delhiveryShipmentService,
        ShiprocketShipmentService $shiprocketShipmentService
    ): void
    {
        $shipment = OrderShipment::findOrFail($this->shipmentId);

        if ($shipment->provider === OrderShipment::PROVIDER_SHIPROCKET) {
            $shiprocketShipmentService->cancelShipment($shipment, $this->auditSource);
            return;
        }

        $delhiveryShipmentService->cancelShipment($shipment, $this->auditSource);
    }
}
