<?php

namespace App\Services\Shipping;

use App\Models\Order;
use App\Models\OrderShipment;
use App\Services\CheckoutService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderShipmentLifecycleService
{
    public function __construct(private readonly CheckoutService $checkoutService)
    {
    }

    public function applyForwardStatus(OrderShipment $shipment, string $normalizedStatus): void
    {
        if ($normalizedStatus !== OrderShipment::STATUS_DELIVERED) {
            return;
        }

        $shipment->loadMissing('order');
        $order = $shipment->order;

        if (!$order) {
            return;
        }

        $dirty = false;

        if (!in_array($order->status, ['delivered', 'return_requested', 'returned', 'cancelled'], true)) {
            $order->status = 'delivered';
            $order->delivered_at = $order->delivered_at ?? now();
            $dirty = true;
        }

        if ($order->payment_method === 'cod' && $order->payment_status !== 'paid') {
            $order->payment_status = 'paid';
            $order->paid_at = $order->paid_at ?? now();
            $dirty = true;
        }

        if ($dirty) {
            $order->save();
        }
    }

    /**
     * Reverse delivered → auto QC pass → refund (prepaid) → restore stock → returned.
     */
    public function completeReturnIfReceived(OrderShipment $shipment, string $normalizedStatus): void
    {
        if ($normalizedStatus !== OrderShipment::STATUS_DELIVERED) {
            return;
        }

        $shipment->loadMissing(['order.orderItems.product']);
        $order = $shipment->order;

        if (!$order || $order->status !== 'return_requested') {
            return;
        }

        try {
            DB::transaction(function () use ($order) {
                $order = Order::query()
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->first();

                if (!$order || $order->status !== 'return_requested') {
                    return;
                }

                if ($order->payment_method === 'online' && $order->payment_status !== 'refunded') {
                    if ($order->payment_status === 'paid') {
                        $this->checkoutService->refundRazorpayPayment($order, 'order_returned');
                        $order->payment_status = 'refunded';
                    }
                }

                $this->checkoutService->restoreStockForOrder($order);
                $order->status = 'returned';
                $order->save();
            });
        } catch (DomainException $e) {
            Log::error('Return completion failed after reverse delivery', [
                'order_id' => $order->id,
                'shipment_id' => $shipment->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
