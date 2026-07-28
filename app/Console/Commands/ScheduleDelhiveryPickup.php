<?php

namespace App\Console\Commands;

use App\Models\OrderShipment;
use App\Services\Delhivery\DelhiveryShipmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScheduleDelhiveryPickup extends Command
{
    protected $signature = 'delhivery:schedule-pickup
                            {--location= : Pickup location name (defaults to DELHIVERY_PICKUP_LOCATION)}
                            {--date= : Pickup date YYYY-MM-DD (defaults to today)}';

    protected $description = 'Schedule Delhivery pickup for manifested shipments waiting pickup.';

    public function handle(DelhiveryShipmentService $delhiveryShipmentService): int
    {
        if (!$delhiveryShipmentService->isConfigured()) {
            $this->error('Delhivery is not configured. Set DELHIVERY_TOKEN and related env vars.');

            return self::FAILURE;
        }

        $pickupLocation = (string) ($this->option('location') ?: config('delhivery.pickup_location'));

        if ($pickupLocation === '') {
            $this->error('Pickup location is missing. Set DELHIVERY_PICKUP_LOCATION or pass --location=');

            return self::FAILURE;
        }

        $pickupDate = (string) ($this->option('date') ?: now()->format('Y-m-d'));

        $pendingCount = OrderShipment::query()
            ->where('provider', OrderShipment::PROVIDER_DELHIVERY)
            ->where('pickup_location', $pickupLocation)
            ->whereNotNull('waybill')
            ->whereNull('pickup_request_id')
            ->where('shipment_status', OrderShipment::STATUS_MANIFESTED)
            ->whereHas('order', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->count();

        if ($pendingCount === 0) {
            $this->info('No manifested Delhivery shipments waiting for pickup.');

            return self::SUCCESS;
        }

        $this->info("Scheduling pickup for {$pendingCount} shipment(s) at {$pickupLocation} on {$pickupDate}...");
        $this->line('Delhivery env: ' . config('delhivery.env'));
        $this->line('Pickup location must exactly match your Delhivery warehouse name.');

        Log::channel('thirdparty')->info('Delhivery cron: schedule-pickup started', [
            'pickup_location' => $pickupLocation,
            'pickup_date' => $pickupDate,
            'pending_count' => $pendingCount,
        ]);

        try {
            $delhiveryShipmentService->processPickupBatch($pickupLocation, $pickupDate, force: true);
        } catch (\Throwable $e) {
            $this->error('Pickup scheduling failed: ' . $e->getMessage());

            Log::channel('thirdparty')->error('Delhivery cron: schedule-pickup failed', [
                'pickup_location' => $pickupLocation,
                'pickup_date' => $pickupDate,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        $scheduledCount = OrderShipment::query()
            ->where('provider', OrderShipment::PROVIDER_DELHIVERY)
            ->where('pickup_location', $pickupLocation)
            ->whereDate('pickup_requested_at', now())
            ->where('shipment_status', OrderShipment::STATUS_PICKUP_SCHEDULED)
            ->count();

        $this->info("Done. {$scheduledCount} shipment(s) marked pickup_scheduled.");

        Log::channel('thirdparty')->info('Delhivery cron: schedule-pickup completed', [
            'pickup_location' => $pickupLocation,
            'pickup_date' => $pickupDate,
            'scheduled_count' => $scheduledCount,
        ]);

        return self::SUCCESS;
    }
}
