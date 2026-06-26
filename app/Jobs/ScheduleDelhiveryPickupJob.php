<?php

namespace App\Jobs;

use App\Services\Delhivery\DelhiveryShipmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScheduleDelhiveryPickupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $pickupLocation,
        public readonly string $pickupDate,
    ) {
    }

    public function backoff(): array
    {
        return [120, 600, 1800];
    }

    public function handle(DelhiveryShipmentService $delhiveryShipmentService): void
    {
        $delhiveryShipmentService->processPickupBatch($this->pickupLocation, $this->pickupDate);
    }
}
