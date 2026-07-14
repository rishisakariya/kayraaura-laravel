<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\ShipmentStatusHistory;
use App\Services\CheckoutService;
use App\Services\Delhivery\DelhiveryShipmentService;
use App\Services\ScratchCardService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderCancellationService
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly ScratchCardService $scratchCardService,
        private readonly DelhiveryShipmentService $delhiveryShipmentService,
    ) {
    }

    /**
     * Cancel the order, restore stock, refund online payments, and return the shipment to cancel with the carrier.
     */
    public function cancel(Order $order, ?string $reason = null, string $cancelledBy = 'customer'): ?OrderShipment
    {
        Log::channel('thirdparty')->info('Order cancellation flow: requested', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'cancelled_by' => $cancelledBy,
            'has_waybill' => (bool) $order->shipment?->waybill,
        ]);

        return DB::transaction(function () use ($order, $reason, $cancelledBy) {
            $auditSource = $cancelledBy === 'admin'
                ? ShipmentStatusHistory::SOURCE_ADMIN
                : ShipmentStatusHistory::SOURCE_SYSTEM;

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

                // Online orders awaiting payment never redeemed their coupon, so this
                // only clears the pending reservation. A cancelled order that was paid
                // keeps its coupon consumed, so the customer scratches a new coupon for
                // their next order rather than reusing the old code.
                if ($order->payment_method === 'online'
                    && in_array($order->payment_status, ['pending', 'failed'], true)
                    && $order->scratch_coupon_code) {
                    $this->scratchCardService->releaseForOrder($order);
                }
            }

            if ($order->payment_method === 'online' && $order->payment_status === 'paid') {
                $razorpayRefund = $this->checkoutService->refundRazorpayPayment($order);

                if (($razorpayRefund['status'] ?? null) === 'processed') {
                    $order->payment_status = 'refunded';
                    $order->refunded_at = now();
                } else {
                    $order->payment_status = 'refund_processing';
                }

                Log::channel('thirdparty')->info('Order cancellation flow: online refund initiated', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'payment_status' => $order->payment_status,
                    'razorpay_refund_id' => $razorpayRefund['id'] ?? null,
                    'razorpay_refund_status' => $razorpayRefund['status'] ?? null,
                ]);
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
                Log::channel('thirdparty')->info('Order cancellation flow: completed without shipment cancel', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'reason' => 'no_waybill',
                ]);

                return null;
            }

            if (in_array($shipment->shipment_status, [
                OrderShipment::STATUS_DELIVERED,
                OrderShipment::STATUS_CANCELLED,
                OrderShipment::STATUS_RTO,
            ], true)) {
                Log::channel('thirdparty')->info('Order cancellation flow: completed without shipment cancel', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'reason' => 'terminal_shipment_status',
                    'shipment_status' => $shipment->shipment_status,
                ]);

                return null;
            }

            if ($shipment->provider === OrderShipment::PROVIDER_DELHIVERY) {
                try {
                    $this->delhiveryShipmentService->cancelShipmentAndVerify($shipment, $auditSource);

                    Log::channel('thirdparty')->info('Order cancellation flow: Delhivery shipment cancelled', [
                        'order_id' => $order->id,
                        'shipment_id' => $shipment->id,
                        'waybill' => $shipment->waybill,
                    ]);

                    return null;
                } catch (\Throwable $e) {
                    Log::channel('thirdparty')->warning('Order cancellation flow: Delhivery shipment cancel deferred to job', [
                        'order_id' => $order->id,
                        'shipment_id' => $shipment->id,
                        'waybill' => $shipment->waybill,
                        'error' => $e->getMessage(),
                    ]);

                    return $shipment;
                }
            }

            Log::channel('thirdparty')->info('Order cancellation flow: completed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'order_status' => $order->status,
                'payment_status' => $order->payment_status,
                'shipment_queued' => $shipment !== null,
            ]);

            return $shipment;
        });
    }
}
