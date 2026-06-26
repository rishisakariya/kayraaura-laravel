<?php

namespace App\Console\Commands;

use App\Services\Delhivery\DelhiveryShipmentService;
use Illuminate\Console\Command;

class ReconcileFailedDelhiveryShipments extends Command
{
    protected $signature = 'delhivery:reconcile-failed-shipments';

    protected $description = 'Recover local Delhivery shipments that exist on Delhivery but are marked failed or missing AWB.';

    public function handle(DelhiveryShipmentService $delhiveryShipmentService): int
    {
        if (!$delhiveryShipmentService->isConfigured()) {
            $this->error('Delhivery is not configured.');

            return self::FAILURE;
        }

        $reconciled = $delhiveryShipmentService->reconcileFailedShipments();

        $this->info("Reconciled {$reconciled} Delhivery shipment(s).");

        return self::SUCCESS;
    }
}
