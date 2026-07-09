<?php

namespace App\Http\Resources;

use App\Models\OrderShipment;
use App\Models\ShipmentStatusHistory;
use App\Services\OrderReturnService;
use App\Services\Shipping\ShippingProviderResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'phone' => $this->user->phone,
                ];
            }),
            'order_number' => $this->order_number,
            'checkout_type' => $this->checkout_type,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            'razorpay_order_id' => $this->razorpay_order_id,
            'razorpay_payment_id' => $this->razorpay_payment_id,
            'paid_at' => $this->paid_at?->format('Y-m-d H:i:s'),
            'delivered_at' => $this->delivered_at?->format('Y-m-d H:i:s'),
            'payment_failed_at' => $this->payment_failed_at?->format('Y-m-d H:i:s'),
            'subtotal' => (float) $this->subtotal,
            'tax_amount' => (float) $this->tax_amount,
            'shipping_amount' => (float) $this->shipping_amount,
            'cod_charge' => (float) ($this->cod_charge ?? 0),
            'buy_two_get_one_discount_amount' => (float) ($this->buy_two_get_one_discount_amount ?? 0),
            'first_order_discount_amount' => (float) ($this->first_order_discount_amount ?? 0),
            'online_payment_discount_amount' => (float) ($this->online_payment_discount_amount ?? 0),
            'scratch_coupon_code' => $this->scratch_coupon_code,
            'discount_percent' => $this->discount_percent,
            'discount_amount' => (float) ($this->discount_amount ?? 0),
            'total_amount' => (float) $this->total_amount,
            'address_id' => $this->address_id,
            'shipping_address' => $this->shipping_address,
            'billing_address' => $this->billing_address,
            'notes' => $this->notes,
            'can_be_returned' => $this->canBeReturned(),
            'can_pay_return_refund' => $this->canPayReturnRefundForAdmin(),
            'return_display_status' => $this->returnDisplayStatus(),
            'return_request' => $this->returnRequestPayload(),
            'return_summary' => $this->returnSummaryPayload(),
            'invoice_download_url' => $this->invoiceDownloadUrl(),
            'can_be_cancelled' => $this->canBeCancelled(),
            'order_items' => OrderItemResource::collection($this->whenLoaded('orderItems')),
            'shipment' => $this->shipmentPayload($request),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }

    private function invoiceDownloadUrl(): ?string
    {
        if (in_array($this->status, ['cancelled'], true) || $this->payment_status === 'failed') {
            return null;
        }

        if ($this->payment_method === 'cod' && !$this->cod_verified_at) {
            return null;
        }

        if ($this->payment_method !== 'cod' && !in_array($this->payment_status, ['paid', 'refunded'], true)) {
            return null;
        }

        return route('orders.invoice.download', ['id' => $this->id]);
    }

    private function shipmentPayload(Request $request): array
    {
        $shipment = $this->relationLoaded('shipment') ? $this->shipment : null;

        $payload = [
            'provider' => $shipment?->provider ?? app(ShippingProviderResolver::class)->activeProvider(),
            'waybill' => $shipment?->waybill,
            'courier_tracking_url' => $shipment?->courier_tracking_url,
            'shipment_status' => $shipment?->shipment_status ?? OrderShipment::STATUS_NOT_CREATED,
            'raw_status' => $shipment?->raw_status,
            'estimated_delivery_at' => $shipment?->estimated_delivery_at?->format('Y-m-d H:i:s'),
            'last_synced_at' => $shipment?->last_synced_at?->format('Y-m-d H:i:s'),
            'is_downloaded' => (bool) ($shipment?->is_downloaded ?? false),
            'return' => array_merge([
                'waybill' => $shipment?->reverse_waybill,
                'status' => $shipment?->reverse_status,
                'tracking_url' => $shipment?->reverse_tracking_url,
                'requested_at' => $shipment?->reverse_requested_at?->format('Y-m-d H:i:s'),
                'estimated_return_at' => $shipment?->estimated_return_at?->format('Y-m-d H:i:s'),
            ], $this->returnRequestSummary()),
        ];

        if ($this->isAdminOrderDetailRequest($request)) {
            $payload = array_merge($payload, [
                'status_location' => $shipment?->status_location,
                'status_instructions' => $shipment?->status_instructions,
                'pickup_location' => $shipment?->pickup_location,
                'payment_mode' => $shipment?->payment_mode,
                'cod_amount' => $shipment ? (float) $shipment->cod_amount : 0.0,
                'weight_grams' => $shipment?->weight_grams,
                'length_cm' => $shipment?->length_cm,
                'width_cm' => $shipment?->width_cm,
                'height_cm' => $shipment?->height_cm,
                'shipping_label_url' => $shipment?->shipping_label_url,
                'manifested_at' => $shipment?->manifested_at?->format('Y-m-d H:i:s'),
                'estimated_delivery_at' => $shipment?->estimated_delivery_at?->format('Y-m-d H:i:s'),
                'delivered_at' => $shipment?->delivered_at?->format('Y-m-d H:i:s'),
                'cancelled_at' => $shipment?->cancelled_at?->format('Y-m-d H:i:s'),
                'rto_at' => $shipment?->rto_at?->format('Y-m-d H:i:s'),
                'failed_reason' => $shipment?->failed_reason,
                'request_payload' => $shipment?->request_payload,
                'response_payload' => $shipment?->response_payload,
                'tracking_payload' => $shipment?->tracking_payload,
                'reverse_failed_reason' => $shipment?->reverse_failed_reason,
                'reverse_request_payload' => $shipment?->reverse_request_payload,
                'reverse_response_payload' => $shipment?->reverse_response_payload,
                'tracking' => $this->trackingTimeline($shipment?->tracking_payload ?? []),
                'status_history' => $shipment && $shipment->relationLoaded('statusHistories')
                    ? ShipmentStatusHistory::formatForApi($shipment->statusHistories)
                    : [],
            ]);
        }

        return $payload;
    }

    private function returnRequestPayload(): ?array
    {
        if (!$this->return_request) {
            return null;
        }

        if (!in_array($this->status, ['return_requested', 'returned'], true)
            && (float) ($this->return_request['total_refunded_amount'] ?? 0) <= 0) {
            return null;
        }

        return $this->returnRequestsPayload();
    }

    private function returnSummaryPayload(): array
    {
        return app(OrderReturnService::class)->buildOrderReturnSummary($this->resource);
    }

    private function returnRequestsPayload(): array
    {
        $returnRequest = $this->return_request ?? [];
        $calculator = app(\App\Services\OrderRefundCalculator::class);
        $requests = $calculator->normalizeReturnRequests($this->resource)
            ->map(function (array $request) {
                $payload = [
                    'id' => $request['id'] ?? null,
                    'status' => $request['status'] ?? 'pending',
                    'reason' => $request['reason'] ?? null,
                    'items' => $request['items'] ?? [],
                    'refund_amount' => isset($request['refund_amount'])
                        ? (float) $request['refund_amount']
                        : null,
                    'is_partial' => (bool) ($request['is_partial'] ?? false),
                    'product_images' => $request['product_images'] ?? [],
                    'requested_at' => $request['requested_at'] ?? null,
                    'received_at' => $request['received_at'] ?? null,
                    'refunded_at' => $request['refunded_at'] ?? null,
                    'completed_at' => $request['completed_at'] ?? null,
                    'can_pay_refund' => $this->isAdminRefundContext($request),
                ];

                if (isset($request['refund_details']) && is_array($request['refund_details'])) {
                    $payload['refund_details'] = $request['refund_details'];
                }

                return $payload;
            })
            ->values()
            ->all();

        return [
            'requests' => $requests,
            'total_refunded_amount' => (float) ($returnRequest['total_refunded_amount'] ?? 0),
            'latest' => $requests !== [] ? $requests[array_key_last($requests)] : null,
        ];
    }

    private function returnRequestSummary(): array
    {
        $requestsPayload = $this->returnRequestsPayload();
        $latest = $requestsPayload['latest'] ?? [];

        if ($latest === []) {
            return [];
        }

        return [
            'reason' => $latest['reason'] ?? null,
            'items' => $latest['items'] ?? [],
            'refund_amount' => $latest['refund_amount'] ?? null,
            'is_partial' => $latest['is_partial'] ?? false,
            'product_images' => $latest['product_images'] ?? [],
            'requested_at' => $latest['requested_at'] ?? null,
            'refund_details' => $latest['refund_details'] ?? null,
        ];
    }

    private function canPayReturnRefundForAdmin(): bool
    {
        if (!$this->isAdminPanelRequest()) {
            return false;
        }

        return app(OrderReturnService::class)->canPayReturnRefund($this->resource);
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function isAdminRefundContext(array $request): bool
    {
        if (!$this->isAdminPanelRequest()) {
            return false;
        }

        return app(OrderReturnService::class)->canPayReturnRefundForRequest($this->resource, $request);
    }

    private function isAdminPanelRequest(): bool
    {
        return request()->is('cpanel/*') || request()->is('api/cpanel/*');
    }

    private function isAdminOrderDetailRequest(Request $request): bool
    {
        return $request->is('cpanel/orders/*')
            || $request->is('api/cpanel/orders/*');
    }

    private function trackingTimeline(array $trackingPayload): array
    {
        // Delhivery shape:
        // ShipmentData[0].Shipment.Scans[].ScanDetail.{Scan, ScannedLocation, Instructions, ScanDateTime}
        $delhiveryScans = data_get($trackingPayload, 'ShipmentData.0.Shipment.Scans', []);
        if (is_array($delhiveryScans) && $delhiveryScans !== []) {
            return collect($delhiveryScans)->map(function (array $scan) {
                $detail = $scan['ScanDetail'] ?? $scan;

                return [
                    'status' => $detail['Scan'] ?? $detail['status'] ?? null,
                    'location' => $detail['ScannedLocation'] ?? $detail['location'] ?? null,
                    'instructions' => $detail['Instructions'] ?? $detail['instructions'] ?? null,
                    'date_time' => $detail['ScanDateTime'] ?? $detail['date_time'] ?? null,
                ];
            })->values()->all();
        }

        // Shiprocket shape:
        // scans[].{date, status, activity, location, sr-status-label}
        $shiprocketScans = data_get($trackingPayload, 'scans', []);
        if (!is_array($shiprocketScans) || $shiprocketScans === []) {
            return [];
        }

        return collect($shiprocketScans)->map(function (array $scan) {
            $status = $scan['sr-status-label'] ?? $scan['status'] ?? null;

            return [
                'status' => $status,
                'location' => $scan['location'] ?? null,
                'instructions' => $scan['activity'] ?? null,
                'date_time' => $scan['date'] ?? null,
            ];
        })->values()->all();
    }
}
