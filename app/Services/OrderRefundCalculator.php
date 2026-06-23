<?php

namespace App\Services;

use App\Models\Order;
use DomainException;
use Illuminate\Support\Collection;

class OrderRefundCalculator
{
    /**
     * @param  array<int, int>  $returnQuantities
     * @return array{items: array<int, array<string, mixed>>, refund_amount: float, is_partial: bool}
     */
    public function calculateReturnRefund(Order $order, array $returnQuantities): array
    {
        $order->loadMissing('orderItems');

        $this->assertValidReturnQuantities($order, $returnQuantities);

        $units = $this->buildUnitsWithRefundValues($order);
        $selectedUnits = $this->selectUnitsForReturn($units, $returnQuantities);

        $items = [];
        $refundAmount = 0.0;

        foreach ($returnQuantities as $orderItemId => $quantity) {
            $orderItem = $order->orderItems->firstWhere('id', $orderItemId);
            $itemUnits = $selectedUnits->where('order_item_id', $orderItemId);
            $lineRefund = round($itemUnits->sum('refund_value'), 2);
            $refundAmount += $lineRefund;

            $items[] = [
                'order_item_id' => (int) $orderItemId,
                'product_id' => $orderItem->product_id,
                'product_name' => $orderItem->product_name,
                'size_text' => $orderItem->size_text,
                'quantity' => (int) $quantity,
                'refund_amount' => $lineRefund,
            ];
        }

        $refundAmount = round($refundAmount, 2);
        $totalReturnableUnits = $order->orderItems->sum(
            fn ($item) => max($item->quantity - (int) $item->returned_quantity, 0)
        );
        $returningUnits = array_sum($returnQuantities);
        $isPartial = $returningUnits < $totalReturnableUnits;

        return [
            'items' => $items,
            'refund_amount' => $refundAmount,
            'is_partial' => $isPartial,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildItemReturnSummary(Order $order): array
    {
        $order->loadMissing('orderItems');
        $units = $this->buildUnitsWithRefundValues($order);

        return $order->orderItems->map(function ($item) use ($units, $order) {
            $returnableQty = max($item->quantity - (int) $item->returned_quantity, 0);
            $pendingReturnQty = $this->pendingReturnQuantityForItem($order, $item->id);
            $availableQty = max($returnableQty - $pendingReturnQty, 0);
            $itemUnits = $units
                ->where('order_item_id', $item->id)
                ->where('is_returned', false)
                ->sortByDesc('refund_value')
                ->take($availableQty);

            return [
                'order_item_id' => $item->id,
                'product_name' => $item->product_name,
                'size_text' => $item->size_text,
                'purchased_quantity' => $item->quantity,
                'returned_quantity' => (int) $item->returned_quantity,
                'pending_return_quantity' => $pendingReturnQty,
                'returnable_quantity' => $availableQty,
                'max_refund_amount' => round($itemUnits->sum('refund_value'), 2),
            ];
        })->values()->all();
    }

    public function buildOrderReturnSummary(Order $order): array
    {
        $order->loadMissing('orderItems');

        $returnedItems = $order->orderItems
            ->filter(fn ($item) => (int) $item->returned_quantity > 0)
            ->map(fn ($item) => [
                'order_item_id' => $item->id,
                'product_name' => $item->product_name,
                'size_text' => $item->size_text,
                'returned_quantity' => (int) $item->returned_quantity,
            ])
            ->values()
            ->all();

        $remainingItems = $order->orderItems
            ->map(function ($item) use ($order) {
                $pending = $this->pendingReturnQuantityForItem($order, $item->id);
                $remaining = max($item->quantity - (int) $item->returned_quantity - $pending, 0);

                return [
                    'order_item_id' => $item->id,
                    'product_name' => $item->product_name,
                    'size_text' => $item->size_text,
                    'remaining_quantity' => $remaining,
                ];
            })
            ->filter(fn ($item) => $item['remaining_quantity'] > 0)
            ->values()
            ->all();

        $returnRequest = $order->return_request ?? [];
        $totalRefunded = round((float) ($returnRequest['total_refunded_amount'] ?? 0), 2);
        $pendingRequest = $this->latestPendingReturnRequest($order);
        $pendingRefund = $pendingRequest
            ? round((float) ($pendingRequest['refund_amount'] ?? 0), 2)
            : 0.0;

        return [
            'returned_items' => $returnedItems,
            'remaining_items' => $remainingItems,
            'total_refunded_amount' => $totalRefunded,
            'pending_refund_amount' => $pendingRefund,
            'item_eligibility' => $this->buildItemReturnSummary($order),
        ];
    }

    public function latestPendingReturnRequest(Order $order): ?array
    {
        return $this->normalizeReturnRequests($order)->first(
            fn ($request) => ($request['status'] ?? 'pending') === 'pending'
        );
    }

    public function normalizeReturnRequests(Order $order): Collection
    {
        $data = $order->return_request ?? [];

        if (isset($data['requests']) && is_array($data['requests'])) {
            return collect($data['requests']);
        }

        if ($data !== [] && isset($data['reason'])) {
            return collect([
                array_merge($data, [
                    'status' => $order->status === 'return_requested' ? 'pending' : 'completed',
                ]),
            ]);
        }

        return collect();
    }

    /**
     * @param  array<int, int>  $returnQuantities
     */
    public function assertValidReturnQuantities(Order $order, array $returnQuantities): void
    {
        if ($returnQuantities === []) {
            throw new DomainException('At least one product must be selected for return');
        }

        if ($order->status === 'return_requested') {
            throw new DomainException('This order already has an active return request');
        }

        foreach ($returnQuantities as $orderItemId => $quantity) {
            if ($quantity < 1) {
                throw new DomainException('Return quantity must be at least 1');
            }

            $orderItem = $order->orderItems->firstWhere('id', $orderItemId);

            if (!$orderItem) {
                throw new DomainException('One or more selected products do not belong to this order');
            }

            $returnable = $orderItem->quantity - (int) $orderItem->returned_quantity;

            if ($quantity > $returnable) {
                throw new DomainException(
                    "Return quantity for {$orderItem->product_name} cannot exceed the remaining purchased quantity ({$returnable} available)"
                );
            }
        }
    }

    /**
     * @param  array<int, array{order_item_id: int, quantity: int}>  $items
     * @return array<int, int>
     */
    public function mapReturnItemsToQuantities(array $items): array
    {
        $quantities = [];

        foreach ($items as $item) {
            $orderItemId = (int) ($item['order_item_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($orderItemId < 1) {
                continue;
            }

            $quantities[$orderItemId] = ($quantities[$orderItemId] ?? 0) + $quantity;
        }

        return $quantities;
    }

    private function buildUnitsWithRefundValues(Order $order): Collection
    {
        $units = $this->expandOrderUnits($order);
        $units = $this->applyBuyTwoGetOneFree($units, $order);

        return $this->assignRefundValues($units, $order);
    }

    private function expandOrderUnits(Order $order): Collection
    {
        $units = collect();

        foreach ($order->orderItems as $orderItem) {
            $unitPrice = round((float) $orderItem->size_price, 2);
            $returned = (int) $orderItem->returned_quantity;

            for ($i = 0; $i < $orderItem->quantity; $i++) {
                $units->push([
                    'order_item_id' => $orderItem->id,
                    'unit_price' => $unitPrice,
                    'is_returned' => $i < $returned,
                    'is_b2g1_free' => false,
                    'refund_value' => 0.0,
                ]);
            }
        }

        return $units;
    }

    private function applyBuyTwoGetOneFree(Collection $units, Order $order): Collection
    {
        $b2g1Discount = (float) ($order->buy_two_get_one_discount_amount ?? 0);

        if ($b2g1Discount <= 0 || $units->isEmpty()) {
            return $units;
        }

        $sortedIndices = $units
            ->values()
            ->sortBy('unit_price')
            ->keys();

        $freeCount = intdiv($units->count(), 3);
        $freeIndices = $sortedIndices->take($freeCount)->flip();

        return $units->map(function (array $unit, int $index) use ($freeIndices) {
            if ($freeIndices->has($index)) {
                $unit['is_b2g1_free'] = true;
            }

            return $unit;
        });
    }

    private function assignRefundValues(Collection $units, Order $order): Collection
    {
        $subtotal = (float) $order->subtotal;

        $preCouponTotal = round(
            $subtotal
            + (float) $order->tax_amount
            + (float) $order->shipping_amount
            - (float) ($order->first_order_discount_amount ?? 0)
            - (float) ($order->online_payment_discount_amount ?? 0)
            + (float) ($order->cod_charge ?? 0),
            2
        );

        $scratchDiscount = (float) ($order->discount_amount ?? 0);

        return $units->map(function (array $unit) use ($subtotal, $preCouponTotal, $scratchDiscount) {
            $contribution = $unit['is_b2g1_free'] ? 0.0 : $unit['unit_price'];
            $share = $subtotal > 0 ? $contribution / $subtotal : 0.0;
            $unit['refund_value'] = round(($share * $preCouponTotal) - ($share * $scratchDiscount), 2);

            return $unit;
        });
    }

    /**
     * @param  array<int, int>  $returnQuantities
     */
    private function selectUnitsForReturn(Collection $units, array $returnQuantities): Collection
    {
        $selected = collect();

        foreach ($returnQuantities as $orderItemId => $quantity) {
            $itemUnits = $units
                ->where('order_item_id', $orderItemId)
                ->where('is_returned', false)
                ->sortByDesc('refund_value')
                ->values()
                ->take($quantity);

            if ($itemUnits->count() < $quantity) {
                throw new DomainException('Insufficient returnable quantity for one or more selected products');
            }

            $selected = $selected->merge($itemUnits);
        }

        return $selected;
    }

    private function pendingReturnQuantityForItem(Order $order, int $orderItemId): int
    {
        if ($order->status !== 'return_requested') {
            return 0;
        }

        $request = $this->latestPendingReturnRequest($order);

        if (!$request) {
            return 0;
        }

        foreach ($request['items'] ?? [] as $item) {
            if ((int) ($item['order_item_id'] ?? 0) === $orderItemId) {
                return (int) ($item['quantity'] ?? 0);
            }
        }

        return 0;
    }
}
