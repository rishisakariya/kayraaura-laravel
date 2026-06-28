<?php

namespace App\Console\Commands;

use App\Jobs\SyncDelhiveryShipmentStatusJob;
use App\Models\OrderShipment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchActiveDelhiveryShipmentSync extends Command
{
    protected $signature = 'delhivery:sync-active-shipments';

    protected $description = 'Dispatch Delhivery tracking sync jobs for active shipments.';

    public function handle(): int
    {
        Log::channel('thirdparty')->info('Delhivery cron: sync-active-shipments started');

        $count = 0;

        OrderShipment::needsDelhiverySync()
            ->select('id')
            ->chunkById(100, function ($shipments) use (&$count) {
                foreach ($shipments as $shipment) {
                    SyncDelhiveryShipmentStatusJob::dispatch($shipment->id);
                    $count++;
                }
            });

        $this->info("Dispatched {$count} Delhivery shipment sync jobs.");

        Log::channel('thirdparty')->info('Delhivery cron: sync-active-shipments completed', [
            'dispatched_jobs' => $count,
        ]);

        return self::SUCCESS;
    }
}
