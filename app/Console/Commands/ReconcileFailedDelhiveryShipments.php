<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Delhivery\DelhiveryShipmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileFailedDelhiveryShipments extends Command
{
    protected $signature = 'delhivery:reconcile-failed-shipments
                            {--order-id= : Reconcile a single local order id}
                            {--order-number= : Reconcile a single order number, e.g. ORD202606265869}';

    protected $description = 'Recover local Delhivery shipments that exist on Delhivery but are marked failed or missing AWB.';

    public function handle(DelhiveryShipmentService $delhiveryShipmentService): int
    {
        Log::channel('thirdparty')->info('Delhivery cron: reconcile-failed-shipments started', [
            'order_id_option' => $this->option('order-id'),
            'order_number_option' => $this->option('order-number'),
        ]);

        if (!$delhiveryShipmentService->isConfigured()) {
            $this->error('Delhivery is not configured.');

            Log::channel('thirdparty')->warning('Delhivery cron: reconcile-failed-shipments aborted, not configured');

            return self::FAILURE;
        }

        $orderId = $this->option('order-id') ? (int) $this->option('order-id') : null;
        $orderNumber = $this->option('order-number') ?: null;

        if ($orderNumber && !$orderId) {
            $orderId = Order::query()->where('order_number', $orderNumber)->value('id');
        }

        if ($orderNumber && !$orderId) {
            $this->error("Order {$orderNumber} was not found.");

            return self::FAILURE;
        }

        $reconciled = $delhiveryShipmentService->reconcileFailedShipments(
            $orderId,
            $orderNumber,
        );

        $this->info("Reconciled {$reconciled} Delhivery shipment(s).");

        Log::channel('thirdparty')->info('Delhivery cron: reconcile-failed-shipments completed', [
            'reconciled' => $reconciled,
        ]);

        if ($orderId) {
            $order = Order::with('shipment')->find($orderId);

            if ($order?->shipment) {
                $this->line("Order {$order->order_number} shipment status: {$order->shipment->shipment_status}");
                $this->line("AWB: " . ($order->shipment->waybill ?? 'none'));
                $this->line('Failed reason: ' . ($order->shipment->failed_reason ?? 'none'));
            }
        }

        return self::SUCCESS;
    }
}
