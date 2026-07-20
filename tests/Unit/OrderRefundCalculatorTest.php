<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderRefundCalculator;
use Illuminate\Support\Collection;
use Tests\TestCase;

class OrderRefundCalculatorTest extends TestCase
{
    private OrderRefundCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = app(OrderRefundCalculator::class);
    }

    public function test_partial_return_refunds_only_selected_product_share(): void
    {
        $order = $this->makeOrder([
            'subtotal' => 800.00,
            'tax_amount' => 24.00,
            'shipping_amount' => 50.00,
            'total_amount' => 874.00,
        ]);

        $itemA = $this->makeOrderItem(1, ['size_price' => 500, 'quantity' => 1, 'total' => 500]);
        $itemB = $this->makeOrderItem(2, ['size_price' => 300, 'quantity' => 1, 'total' => 300]);
        $order->setRelation('orderItems', new Collection([$itemA, $itemB]));

        $result = $this->calculator->calculateReturnRefund($order, [
            2 => 1,
        ]);

        $this->assertTrue($result['is_partial']);
        $this->assertSame(327.75, $result['refund_amount']);
        $this->assertCount(1, $result['items']);
        $this->assertSame(2, $result['items'][0]['order_item_id']);
    }

    public function test_quantity_based_return_scales_refund(): void
    {
        $order = $this->makeOrder([
            'subtotal' => 1000.00,
            'tax_amount' => 30.00,
            'shipping_amount' => 0.00,
            'total_amount' => 1030.00,
        ]);

        $itemA = $this->makeOrderItem(1, ['size_price' => 500, 'quantity' => 2, 'total' => 1000]);
        $order->setRelation('orderItems', new Collection([$itemA]));

        $oneQty = $this->calculator->calculateReturnRefund($order, [1 => 1]);
        $twoQty = $this->calculator->calculateReturnRefund($order, [1 => 2]);

        $this->assertSame(515.00, $oneQty['refund_amount']);
        $this->assertSame(1030.00, $twoQty['refund_amount']);
        $this->assertFalse($twoQty['is_partial']);
    }

    public function test_buy_two_get_one_free_makes_cheapest_unit_non_refundable(): void
    {
        $order = $this->makeOrder([
            'subtotal' => 800.00,
            'tax_amount' => 24.00,
            'shipping_amount' => 50.00,
            'buy_two_get_one_discount_amount' => 200.00,
            'buy_qty' => 2,
            'get_qty' => 1,
            'total_amount' => 874.00,
        ]);

        $itemA = $this->makeOrderItem(1, ['size_price' => 500, 'quantity' => 1, 'total' => 500]);
        $itemB = $this->makeOrderItem(2, ['size_price' => 300, 'quantity' => 1, 'total' => 300]);
        $itemC = $this->makeOrderItem(3, ['size_price' => 200, 'quantity' => 1, 'total' => 200]);
        $order->setRelation('orderItems', new Collection([$itemA, $itemB, $itemC]));

        $returnFreeItem = $this->calculator->calculateReturnRefund($order, [3 => 1]);
        $returnPaidItem = $this->calculator->calculateReturnRefund($order, [1 => 1]);

        $this->assertSame(0.0, $returnFreeItem['refund_amount']);
        $this->assertSame(546.25, $returnPaidItem['refund_amount']);
    }

    public function test_buy_three_get_one_free_uses_order_snapshot_rule(): void
    {
        $order = $this->makeOrder([
            'subtotal' => 1500.00,
            'tax_amount' => 45.00,
            'shipping_amount' => 0.00,
            'buy_two_get_one_discount_amount' => 200.00,
            'buy_qty' => 3,
            'get_qty' => 1,
            'total_amount' => 1545.00,
        ]);

        $itemA = $this->makeOrderItem(1, ['size_price' => 500, 'quantity' => 1, 'total' => 500]);
        $itemB = $this->makeOrderItem(2, ['size_price' => 400, 'quantity' => 1, 'total' => 400]);
        $itemC = $this->makeOrderItem(3, ['size_price' => 300, 'quantity' => 1, 'total' => 300]);
        $itemD = $this->makeOrderItem(4, ['size_price' => 200, 'quantity' => 1, 'total' => 200]);
        $order->setRelation('orderItems', new Collection([$itemA, $itemB, $itemC, $itemD]));

        $returnFreeItem = $this->calculator->calculateReturnRefund($order, [4 => 1]);
        $returnPaidItem = $this->calculator->calculateReturnRefund($order, [1 => 1]);

        $this->assertSame(0.0, $returnFreeItem['refund_amount']);
        $this->assertSame(515.00, $returnPaidItem['refund_amount']);
    }

    public function test_legacy_orders_without_buy_get_qty_fallback_to_buy_two_get_one(): void
    {
        $order = $this->makeOrder([
            'subtotal' => 800.00,
            'tax_amount' => 24.00,
            'shipping_amount' => 50.00,
            'buy_two_get_one_discount_amount' => 200.00,
            'buy_qty' => null,
            'get_qty' => null,
            'total_amount' => 874.00,
        ]);

        $itemA = $this->makeOrderItem(1, ['size_price' => 500, 'quantity' => 1, 'total' => 500]);
        $itemB = $this->makeOrderItem(2, ['size_price' => 300, 'quantity' => 1, 'total' => 300]);
        $itemC = $this->makeOrderItem(3, ['size_price' => 200, 'quantity' => 1, 'total' => 200]);
        $order->setRelation('orderItems', new Collection([$itemA, $itemB, $itemC]));

        $returnFreeItem = $this->calculator->calculateReturnRefund($order, [3 => 1]);
        $returnPaidItem = $this->calculator->calculateReturnRefund($order, [1 => 1]);

        $this->assertSame(0.0, $returnFreeItem['refund_amount']);
        $this->assertSame(546.25, $returnPaidItem['refund_amount']);
    }

    public function test_cannot_return_more_than_remaining_quantity(): void
    {
        $order = $this->makeOrder([
            'subtotal' => 500.00,
            'tax_amount' => 15.00,
            'shipping_amount' => 50.00,
            'total_amount' => 565.00,
        ]);

        $item = $this->makeOrderItem(1, [
            'size_price' => 500,
            'quantity' => 2,
            'total' => 1000,
            'returned_quantity' => 1,
        ]);
        $order->setRelation('orderItems', new Collection([$item]));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('cannot exceed the remaining purchased quantity');

        $this->calculator->calculateReturnRefund($order, [1 => 2]);
    }

    public function test_cod_and_scratch_coupon_are_included_in_refund(): void
    {
        $order = $this->makeOrder([
            'payment_method' => 'cod',
            'subtotal' => 800.00,
            'tax_amount' => 24.00,
            'shipping_amount' => 50.00,
            'cod_charge' => 87.40,
            'discount_amount' => 96.14,
            'total_amount' => 865.26,
        ]);

        $itemA = $this->makeOrderItem(1, ['size_price' => 500, 'quantity' => 1, 'total' => 500]);
        $itemB = $this->makeOrderItem(2, ['size_price' => 300, 'quantity' => 1, 'total' => 300]);
        $order->setRelation('orderItems', new Collection([$itemA, $itemB]));

        $result = $this->calculator->calculateReturnRefund($order, [2 => 1]);

        $this->assertSame(324.47, $result['refund_amount']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOrder(array $attributes = []): Order
    {
        return new Order(array_merge([
            'order_number' => 'ORD123456',
            'checkout_type' => 'cart',
            'status' => 'delivered',
            'payment_method' => 'online',
            'payment_status' => 'paid',
            'buy_two_get_one_discount_amount' => 0,
            'first_order_discount_amount' => 0,
            'online_payment_discount_amount' => 0,
            'cod_charge' => 0,
            'discount_amount' => 0,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOrderItem(int $id, array $attributes = []): OrderItem
    {
        $item = new OrderItem(array_merge([
            'product_id' => 1,
            'product_size_id' => 1,
            'product_name' => 'Test Product',
            'product_slug' => 'test-product',
            'size_text' => 'M',
            'size_price' => 500,
            'quantity' => 1,
            'returned_quantity' => 0,
            'price' => 500,
            'total' => 500,
        ], $attributes));

        $item->id = $id;

        return $item;
    }
}
