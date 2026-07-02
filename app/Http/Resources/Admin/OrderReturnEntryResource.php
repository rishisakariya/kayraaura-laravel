<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderReturnService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class OrderReturnEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource['order'];
        /** @var array<string, mixed> $returnRequest */
        $returnRequest = $this->resource['return_request'];

        $orderPayload = (new OrderResource($order))->toArray($request);
        $formattedRequest = $this->formatReturnRequest($order, $returnRequest);

        return array_merge(
            Arr::only($orderPayload, [
                'status',
                'return_display_status',
                'can_be_returned',
                'can_pay_return_refund',
                'return_summary',
                'shipment',
            ]),
            [
                'return_request' => $orderPayload['return_request'] ?? $this->entryReturnRequestPayload($formattedRequest),
                'return_request_id' => $returnRequest['id'] ?? null,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_method' => $order->payment_method,
                'order_status' => $order->status,
                'shipment_return_status' => $order->shipment?->reverse_status,
                'payment_status' => $order->payment_status,
                'order_total_amount' => (float) $order->total_amount,
                'customer' => [
                    'id' => $order->user?->id,
                    'name' => $order->user?->name,
                    'email' => $order->user?->email,
                    'phone' => $order->user?->phone,
                ],
                'reason' => $returnRequest['reason'] ?? null,
                'items' => $returnRequest['items'] ?? [],
                'refund_amount' => isset($returnRequest['refund_amount'])
                    ? (float) $returnRequest['refund_amount']
                    : null,
                'is_partial' => (bool) ($returnRequest['is_partial'] ?? false),
                'return_request_status' => $returnRequest['status'] ?? 'pending',
                'product_images' => $returnRequest['product_images'] ?? [],
                'refund_details' => is_array($returnRequest['refund_details'] ?? null)
                    ? $returnRequest['refund_details']
                    : null,
                'requested_at' => $returnRequest['requested_at'] ?? null,
                'received_at' => $returnRequest['received_at'] ?? null,
                'refunded_at' => $returnRequest['refunded_at'] ?? null,
                'completed_at' => $returnRequest['completed_at'] ?? null,
                'refund_method' => $returnRequest['refund_method'] ?? null,
                'razorpay_refund_id' => $returnRequest['razorpay_refund_id'] ?? null,
                'razorpay_payout_id' => $returnRequest['razorpay_payout_id'] ?? null,
                'upi_transaction_reference' => $returnRequest['upi_transaction_reference'] ?? null,
                'can_pay_refund' => (bool) ($this->resource['can_pay_refund'] ?? false),
                'cod_refund_requires_upi_reference' => (bool) (
                    $this->resource['cod_refund_requires_upi_reference'] ?? false
                ),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $returnRequest
     * @return array<string, mixed>
     */
    private function formatReturnRequest(Order $order, array $returnRequest): array
    {
        $payload = [
            'id' => $returnRequest['id'] ?? null,
            'status' => $returnRequest['status'] ?? 'pending',
            'reason' => $returnRequest['reason'] ?? null,
            'items' => $returnRequest['items'] ?? [],
            'refund_amount' => isset($returnRequest['refund_amount'])
                ? (float) $returnRequest['refund_amount']
                : null,
            'is_partial' => (bool) ($returnRequest['is_partial'] ?? false),
            'product_images' => $returnRequest['product_images'] ?? [],
            'requested_at' => $returnRequest['requested_at'] ?? null,
            'received_at' => $returnRequest['received_at'] ?? null,
            'refunded_at' => $returnRequest['refunded_at'] ?? null,
            'completed_at' => $returnRequest['completed_at'] ?? null,
            'can_pay_refund' => app(OrderReturnService::class)
                ->canPayReturnRefundForRequest($order, $returnRequest),
        ];

        if (isset($returnRequest['refund_details']) && is_array($returnRequest['refund_details'])) {
            $payload['refund_details'] = $returnRequest['refund_details'];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $formattedRequest
     * @return array<string, mixed>
     */
    private function entryReturnRequestPayload(array $formattedRequest): array
    {
        $returnRequest = $this->resource['order']->return_request ?? [];

        return [
            'requests' => [$formattedRequest],
            'total_refunded_amount' => (float) ($returnRequest['total_refunded_amount'] ?? 0),
            'latest' => $formattedRequest,
        ];
    }
}
