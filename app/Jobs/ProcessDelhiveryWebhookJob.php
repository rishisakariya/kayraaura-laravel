<?php

namespace App\Jobs;

use App\Services\Delhivery\DelhiveryShipmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessDelhiveryWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly array $payload)
    {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(DelhiveryShipmentService $shipmentService): void
    {
        $waybill = $this->payload['waybill']
            ?? $this->payload['awb']
            ?? $this->payload['AWB']
            ?? null;

        Log::channel('thirdparty')->info('Delhivery job: webhook processing started', [
            'waybill' => $waybill,
            'attempt' => $this->attempts(),
        ]);

        $shipment = $shipmentService->applyWebhookPayload($this->payload);

        Log::channel('thirdparty')->info('Delhivery job: webhook processing completed', [
            'waybill' => $waybill,
            'shipment_id' => $shipment?->id,
            'matched' => $shipment !== null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('thirdparty')->error('Delhivery job: webhook processing failed', [
            'payload' => $this->payload,
            'error' => $exception->getMessage(),
        ]);
    }
}
