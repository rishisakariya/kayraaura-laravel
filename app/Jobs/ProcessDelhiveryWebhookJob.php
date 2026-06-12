<?php

namespace App\Jobs;

use App\Services\Delhivery\DelhiveryShipmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
        $shipmentService->applyWebhookPayload($this->payload);
    }
}
