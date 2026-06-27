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
use Illuminate\Support\Facades\Log;
use Throwable;

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
        Log::info('Delhivery job: cancel shipment started', [
            'shipment_id' => $this->shipmentId,
            'audit_source' => $this->auditSource,
            'attempt' => $this->attempts(),
        ]);

        $shipment = OrderShipment::findOrFail($this->shipmentId);

        if ($shipment->provider === OrderShipment::PROVIDER_SHIPROCKET) {
            $shiprocketShipmentService->cancelShipment($shipment, $this->auditSource);

            Log::info('Delhivery job: cancel shipment completed via Shiprocket', [
                'shipment_id' => $this->shipmentId,
            ]);

            return;
        }

        $delhiveryShipmentService->cancelShipmentAndVerify($shipment, $this->auditSource);

        Log::info('Delhivery job: cancel shipment completed', [
            'shipment_id' => $this->shipmentId,
            'waybill' => $shipment->waybill,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Delhivery job: cancel shipment failed', [
            'shipment_id' => $this->shipmentId,
            'error' => $exception->getMessage(),
        ]);
    }
}
