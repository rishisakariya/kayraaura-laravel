<?php

namespace App\Jobs;

use App\Services\Delhivery\DelhiveryShipmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScheduleDelhiveryPickupJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $pickupLocation,
        public readonly string $pickupDate,
    ) {
    }

    public function uniqueId(): string
    {
        return $this->pickupLocation . ':' . $this->pickupDate;
    }

    public function backoff(): array
    {
        return [120, 600, 1800];
    }

    public function handle(DelhiveryShipmentService $delhiveryShipmentService): void
    {
        Log::channel('thirdparty')->info('Delhivery job: schedule pickup started', [
            'pickup_location' => $this->pickupLocation,
            'pickup_date' => $this->pickupDate,
            'attempt' => $this->attempts(),
        ]);

        try {
            $delhiveryShipmentService->processPickupBatch($this->pickupLocation, $this->pickupDate);
        } catch (Throwable $e) {
            // Duplicate pickup for same location/date/slot is expected; treat as success.
            if (preg_match('/already exist/i', $e->getMessage()) === 1) {
                Log::channel('thirdparty')->info('Delhivery job: schedule pickup already exists, treated as success', [
                    'pickup_location' => $this->pickupLocation,
                    'pickup_date' => $this->pickupDate,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            throw $e;
        }

        Log::channel('thirdparty')->info('Delhivery job: schedule pickup completed', [
            'pickup_location' => $this->pickupLocation,
            'pickup_date' => $this->pickupDate,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('thirdparty')->error('Delhivery job: schedule pickup failed', [
            'pickup_location' => $this->pickupLocation,
            'pickup_date' => $this->pickupDate,
            'error' => $exception->getMessage(),
        ]);
    }
}
