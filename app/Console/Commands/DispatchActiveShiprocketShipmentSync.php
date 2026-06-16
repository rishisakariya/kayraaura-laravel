<?php

namespace App\Console\Commands;

use App\Jobs\SyncDelhiveryShipmentStatusJob;
use App\Models\OrderShipment;
use Illuminate\Console\Command;

class DispatchActiveShiprocketShipmentSync extends Command
{
    protected $signature = 'shiprocket:sync-active-shipments';

    protected $description = 'Dispatch Shiprocket tracking sync jobs for active shipments.';

    public function handle(): int
    {
        $count = 0;

        OrderShipment::activeForShiprocketSync()
            ->select('id')
            ->chunkById(100, function ($shipments) use (&$count) {
                foreach ($shipments as $shipment) {
                    SyncDelhiveryShipmentStatusJob::dispatch($shipment->id);
                    $count++;
                }
            });

        $this->info("Dispatched {$count} Shiprocket shipment sync jobs.");

        return self::SUCCESS;
    }
}

