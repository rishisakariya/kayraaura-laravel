<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderReturnEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'return_request_id' => $this->resource['return_request_id'] ?? null,
            'order_id' => $this->resource['order_id'] ?? null,
            'order_number' => $this->resource['order_number'] ?? null,
            'payment_method' => $this->resource['payment_method'] ?? null,
            'order_status' => $this->resource['order_status'] ?? null,
            'return_display_status' => $this->resource['return_display_status'] ?? null,
            'shipment_return_status' => $this->resource['shipment_return_status'] ?? null,
            'payment_status' => $this->resource['payment_status'] ?? null,
            'order_total_amount' => $this->resource['order_total_amount'] ?? null,
            'customer' => $this->resource['customer'] ?? null,
            'reason' => $this->resource['reason'] ?? null,
            'items' => $this->resource['items'] ?? [],
            'refund_amount' => $this->resource['refund_amount'] ?? null,
            'is_partial' => (bool) ($this->resource['is_partial'] ?? false),
            'status' => $this->resource['status'] ?? null,
            'product_images' => $this->resource['product_images'] ?? [],
            'refund_details' => $this->resource['refund_details'] ?? null,
            'requested_at' => $this->resource['requested_at'] ?? null,
            'received_at' => $this->resource['received_at'] ?? null,
            'refunded_at' => $this->resource['refunded_at'] ?? null,
            'completed_at' => $this->resource['completed_at'] ?? null,
            'refund_method' => $this->resource['refund_method'] ?? null,
            'razorpay_refund_id' => $this->resource['razorpay_refund_id'] ?? null,
            'razorpay_payout_id' => $this->resource['razorpay_payout_id'] ?? null,
            'upi_transaction_reference' => $this->resource['upi_transaction_reference'] ?? null,
            'can_pay_refund' => (bool) ($this->resource['can_pay_refund'] ?? false),
            'cod_refund_requires_upi_reference' => (bool) (
                $this->resource['cod_refund_requires_upi_reference'] ?? false
            ),
        ];
    }
}
