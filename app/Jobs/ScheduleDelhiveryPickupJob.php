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
        Log::info('Delhivery job: schedule pickup started', [
            'pickup_location' => $this->pickupLocation,
            'pickup_date' => $this->pickupDate,
            'attempt' => $this->attempts(),
        ]);

        $delhiveryShipmentService->processPickupBatch($this->pickupLocation, $this->pickupDate);

        Log::info('Delhivery job: schedule pickup completed', [
            'pickup_location' => $this->pickupLocation,
            'pickup_date' => $this->pickupDate,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Delhivery job: schedule pickup failed', [
            'pickup_location' => $this->pickupLocation,
            'pickup_date' => $this->pickupDate,
            'error' => $exception->getMessage(),
        ]);
    }
}
