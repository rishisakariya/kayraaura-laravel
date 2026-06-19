<?php

namespace App\Observers;

use App\Models\OrderShipment;
use App\Models\ShipmentStatusHistory;

class OrderShipmentObserver
{
    public function created(OrderShipment $shipment): void
    {
        if ($shipment->shipment_status === OrderShipment::STATUS_NOT_CREATED) {
            return;
        }

        $this->record(
            $shipment,
            ShipmentStatusHistory::DIRECTION_FORWARD,
            null,
            $shipment->shipment_status,
            $shipment->raw_status,
        );
    }

    public function updating(OrderShipment $shipment): void
    {
        if ($shipment->isDirty('shipment_status')) {
            $this->record(
                $shipment,
                ShipmentStatusHistory::DIRECTION_FORWARD,
                $shipment->getOriginal('shipment_status'),
                $shipment->shipment_status,
                $shipment->isDirty('raw_status') ? $shipment->raw_status : null,
            );
        }

        if ($shipment->isDirty('reverse_status')) {
            $this->record(
                $shipment,
                ShipmentStatusHistory::DIRECTION_REVERSE,
                $shipment->getOriginal('reverse_status'),
                $shipment->reverse_status,
                null,
            );
        }
    }

    private function record(
        OrderShipment $shipment,
        string $direction,
        ?string $oldStatus,
        ?string $newStatus,
        ?string $rawStatus,
    ): void {
        if ($newStatus === null || $oldStatus === $newStatus) {
            return;
        }

        ShipmentStatusHistory::create([
            'shipment_id' => $shipment->id,
            'direction' => $direction,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'raw_status' => $rawStatus,
            'source' => $shipment->auditSource ?? ShipmentStatusHistory::SOURCE_SYSTEM,
            'created_at' => now(),
        ]);
    }
}
