<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderShipment;
use DomainException;
use Illuminate\Support\Facades\DB;

class OrderCancellationService
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly ScratchCardService $scratchCardService,
    ) {
    }

    /**
     * Cancel the order, restore stock, refund online payments, and return the shipment to cancel with the carrier.
     */
    public function cancel(Order $order, ?string $reason = null, string $cancelledBy = 'customer'): ?OrderShipment
    {
        return DB::transaction(function () use ($order, $reason, $cancelledBy) {
            $order = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->with('shipment')
                ->firstOrFail();

            $wasAlreadyCancelled = $order->status === 'cancelled';

            if (!$wasAlreadyCancelled) {
                if (!$order->canBeCancelled()) {
                    throw new DomainException(
                        $order->cancellationBlockReason() ?? 'Order cannot be cancelled'
                    );
                }

                $order->cancel();

                if ($order->payment_method === 'cod' || $order->payment_status === 'paid') {
                    $this->checkoutService->restoreStockForOrder($order);
                }

                if ($order->payment_method === 'online'
                    && in_array($order->payment_status, ['pending', 'failed'], true)
                    && $order->scratch_coupon_code) {
                    $this->scratchCardService->releaseForOrder($order);
                }
            }

            if ($order->payment_method === 'online' && $order->payment_status === 'paid') {
                $this->checkoutService->refundRazorpayPayment($order);
                $order->payment_status = 'refunded';
            }

            if ($reason) {
                $prefix = $cancelledBy === 'admin'
                    ? 'Admin cancellation reason:'
                    : 'Cancellation reason:';
                $order->notes = ($order->notes ? $order->notes . "\n\n" : '')
                    . $prefix . ' ' . $reason;
            }

            $order->save();

            $shipment = $order->shipment;

            if (!$shipment?->waybill) {
                return null;
            }

            if (in_array($shipment->shipment_status, [
                OrderShipment::STATUS_DELIVERED,
                OrderShipment::STATUS_CANCELLED,
                OrderShipment::STATUS_RTO,
            ], true)) {
                return null;
            }

            return $shipment;
        });
    }
}
