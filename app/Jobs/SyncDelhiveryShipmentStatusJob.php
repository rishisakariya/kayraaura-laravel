<?php

namespace App\Jobs;

use App\Models\OrderShipment;
use App\Services\Delhivery\DelhiveryShipmentService;
use App\Services\Shiprocket\ShiprocketShipmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncDelhiveryShipmentStatusJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $shipmentId)
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
        Log::info('Delhivery job: sync shipment status started', [
            'shipment_id' => $this->shipmentId,
            'attempt' => $this->attempts(),
        ]);

        $shipment = OrderShipment::findOrFail($this->shipmentId);

        if ($shipment->provider === OrderShipment::PROVIDER_SHIPROCKET) {
            $shiprocketShipmentService->syncShipment($shipment);

            Log::info('Delhivery job: sync shipment status completed via Shiprocket', [
                'shipment_id' => $this->shipmentId,
            ]);

            return;
        }

        $delhiveryShipmentService->syncShipment($shipment);
        $shipment = $delhiveryShipmentService->syncReverseShipment($shipment->refresh());

        Log::info('Delhivery job: sync shipment status completed', [
            'shipment_id' => $this->shipmentId,
            'waybill' => $shipment->waybill,
            'shipment_status' => $shipment->shipment_status,
            'reverse_status' => $shipment->reverse_status,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Delhivery job: sync shipment status failed', [
            'shipment_id' => $this->shipmentId,
            'error' => $exception->getMessage(),
        ]);
    }
}
