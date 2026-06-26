<?php

namespace App\Services\Shipping;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderShipment;
use App\Services\CheckoutService;
use App\Services\OrderRefundCalculator;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderShipmentLifecycleService
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly OrderRefundCalculator $refundCalculator,
    ) {
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

            Log::info('Shipment lifecycle: forward delivery applied to order', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'shipment_id' => $shipment->id,
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status,
            ]);
        }
    }

    /**
     * Reverse delivered → restore stock → mark return received (online refunds are manual).
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
                    ->with('orderItems')
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->first();

                if (!$order || $order->status !== 'return_requested') {
                    return;
                }

                $pendingRequest = $this->refundCalculator->latestPendingReturnRequest($order);

                if (!$pendingRequest) {
                    return;
                }

                $returnItems = $pendingRequest['items'] ?? [];
                $refundAmount = round((float) ($pendingRequest['refund_amount'] ?? 0), 2);

                foreach ($returnItems as $returnItem) {
                    $orderItemId = (int) ($returnItem['order_item_id'] ?? 0);
                    $quantity = (int) ($returnItem['quantity'] ?? 0);

                    if ($orderItemId < 1 || $quantity < 1) {
                        continue;
                    }

                    OrderItem::query()
                        ->where('order_id', $order->id)
                        ->whereKey($orderItemId)
                        ->increment('returned_quantity', $quantity);
                }

                $this->checkoutService->restoreStockForReturnedItems($order, $returnItems);

                $isOnlineRefundDue = $order->payment_method === 'online'
                    && $refundAmount > 0
                    && $order->payment_status === 'paid';
                $isCodUpiRefundDue = $order->payment_method === 'cod'
                    && $refundAmount > 0
                    && !empty($pendingRequest['refund_details']['upi_id']);
                $receivedStatus = ($isOnlineRefundDue || $isCodUpiRefundDue)
                    ? 'awaiting_refund'
                    : 'completed';

                $returnRequest = $order->return_request ?? ['requests' => [], 'total_refunded_amount' => 0];

                if (!isset($returnRequest['requests'])) {
                    $returnRequest = [
                        'requests' => [$pendingRequest],
                        'total_refunded_amount' => 0.0,
                    ];
                }

                $returnRequest['requests'] = collect($returnRequest['requests'])
                    ->map(function (array $request) use ($pendingRequest, $receivedStatus) {
                        if (($request['id'] ?? null) === ($pendingRequest['id'] ?? null)) {
                            $request['status'] = $receivedStatus;
                            $request['received_at'] = now()->toDateTimeString();

                            if ($receivedStatus === 'completed') {
                                $request['completed_at'] = $request['received_at'];
                            }
                        }

                        return $request;
                    })
                    ->all();

                if ($receivedStatus === 'completed') {
                    $returnRequest['total_refunded_amount'] = round(
                        (float) ($returnRequest['total_refunded_amount'] ?? 0) + $refundAmount,
                        2
                    );
                }

                $order->return_request = $returnRequest;
                $order->load('orderItems');
                $order->status = $this->allItemsReturned($order) ? 'returned' : 'delivered';
                $order->save();

                Log::info('Return flow: return received at warehouse', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'shipment_id' => $shipment->id,
                    'return_request_id' => $pendingRequest['id'] ?? null,
                    'received_status' => $receivedStatus,
                    'refund_amount' => $refundAmount,
                    'order_status' => $order->status,
                ]);
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

    private function allItemsReturned(Order $order): bool
    {
        return $order->orderItems->every(
            fn (OrderItem $item) => (int) $item->returned_quantity >= (int) $item->quantity
        );
    }
}
