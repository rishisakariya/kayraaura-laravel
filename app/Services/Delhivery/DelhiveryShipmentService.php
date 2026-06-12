<?php

namespace App\Services\Delhivery;

use App\Models\Order;
use App\Models\OrderShipment;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DelhiveryShipmentService
{
    public function __construct(private readonly DelhiveryClient $client)
    {
    }

    public function createShipment(Order $order): OrderShipment
    {
        return DB::transaction(function () use ($order) {
            $order = Order::with(['orderItems.product'])
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $shipment = OrderShipment::firstOrCreate(
                ['order_id' => $order->id],
                [
                    'provider' => OrderShipment::PROVIDER_DELHIVERY,
                    'shipment_status' => OrderShipment::STATUS_NOT_CREATED,
                    'pickup_location' => config('delhivery.pickup_location'),
                ]
            );

            $shipment->refresh();

            if ($shipment->hasWaybill()) {
                return $shipment;
            }

            $payload = $this->buildCreatePayload($order);

            $shipment->fill([
                'provider' => OrderShipment::PROVIDER_DELHIVERY,
                'shipment_status' => OrderShipment::STATUS_NOT_CREATED,
                'pickup_location' => config('delhivery.pickup_location'),
                'payment_mode' => $this->paymentMode($order),
                'cod_amount' => $this->codAmount($order),
                'weight_grams' => $this->calculateWeight($order),
                'length_cm' => (int) config('delhivery.default_length_cm', 10),
                'width_cm' => (int) config('delhivery.default_width_cm', 10),
                'height_cm' => (int) config('delhivery.default_height_cm', 5),
                'request_payload' => $payload,
                'failed_reason' => null,
            ])->save();

            try {
                $responsePayload = $this->client->createShipment($payload);
                $waybill = $this->extractWaybill($responsePayload);

                if (!$waybill) {
                    throw new DomainException('Delhivery did not return an AWB number');
                }

                $shipment->fill([
                    'waybill' => $waybill,
                    'provider_reference' => $this->extractProviderReference($responsePayload),
                    'delhivery_order_id' => $this->extractDelhiveryOrderId($responsePayload),
                    'shipment_status' => OrderShipment::STATUS_MANIFESTED,
                    'raw_status' => $this->extractRawStatus($responsePayload) ?? 'Manifested',
                    'courier_tracking_url' => "https://www.delhivery.com/track/package/{$waybill}",
                    'manifested_at' => now(),
                    'response_payload' => $responsePayload,
                    'failed_reason' => null,
                ])->save();

                return $shipment;
            } catch (\Throwable $e) {
                $shipment->fill([
                    'shipment_status' => OrderShipment::STATUS_FAILED,
                    'failed_reason' => $e->getMessage(),
                ])->save();

                Log::error('Delhivery shipment creation failed', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    public function queuePlaceholder(Order $order): OrderShipment
    {
        return OrderShipment::firstOrCreate(
            ['order_id' => $order->id],
            [
                'provider' => OrderShipment::PROVIDER_DELHIVERY,
                'shipment_status' => OrderShipment::STATUS_NOT_CREATED,
                'pickup_location' => config('delhivery.pickup_location'),
            ]
        );
    }

    public function syncShipment(OrderShipment $shipment): OrderShipment
    {
        if (!$shipment->hasWaybill() || in_array($shipment->shipment_status, OrderShipment::TERMINAL_STATUSES, true)) {
            return $shipment;
        }

        try {
            $payload = $this->client->trackShipment($shipment->waybill);
            $scan = $this->latestTrackingScan($payload);
            $rawStatus = $scan['status'] ?? $scan['Scan'] ?? $this->extractRawStatus($payload);
            $normalizedStatus = $this->normalizeStatus($rawStatus);

            $shipment->fill([
                'shipment_status' => $normalizedStatus,
                'raw_status' => $rawStatus,
                'status_location' => $scan['location'] ?? $scan['ScannedLocation'] ?? null,
                'status_instructions' => $scan['instructions'] ?? $scan['Instructions'] ?? null,
                'tracking_payload' => $payload,
                'last_synced_at' => now(),
            ]);

            if ($normalizedStatus === OrderShipment::STATUS_DELIVERED && !$shipment->delivered_at) {
                $shipment->delivered_at = now();
            }

            if ($normalizedStatus === OrderShipment::STATUS_RTO && !$shipment->rto_at) {
                $shipment->rto_at = now();
            }

            if ($normalizedStatus === OrderShipment::STATUS_CANCELLED && !$shipment->cancelled_at) {
                $shipment->cancelled_at = now();
            }

            $shipment->save();

            return $shipment;
        } catch (\Throwable $e) {
            $shipment->fill([
                'failed_reason' => $e->getMessage(),
                'last_synced_at' => now(),
            ])->save();

            Log::warning('Delhivery tracking sync failed', [
                'shipment_id' => $shipment->id,
                'waybill' => $shipment->waybill,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function cancelShipment(OrderShipment $shipment): OrderShipment
    {
        if (!$shipment->hasWaybill() || in_array($shipment->shipment_status, [
            OrderShipment::STATUS_DELIVERED,
            OrderShipment::STATUS_CANCELLED,
            OrderShipment::STATUS_RTO,
        ], true)) {
            return $shipment;
        }

        try {
            $payload = $this->client->cancelShipment($shipment->waybill);

            $shipment->fill([
                'shipment_status' => OrderShipment::STATUS_CANCELLED,
                'raw_status' => $this->extractRawStatus($payload) ?? 'Cancelled',
                'cancelled_at' => now(),
                'response_payload' => array_merge($shipment->response_payload ?? [], ['cancel' => $payload]),
                'failed_reason' => null,
            ])->save();

            return $shipment;
        } catch (\Throwable $e) {
            $shipment->fill(['failed_reason' => $e->getMessage()])->save();

            Log::warning('Delhivery shipment cancellation failed', [
                'shipment_id' => $shipment->id,
                'waybill' => $shipment->waybill,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function applyWebhookPayload(array $payload): ?OrderShipment
    {
        $waybill = $payload['waybill']
            ?? $payload['awb']
            ?? $payload['AWB']
            ?? Arr::get($payload, 'Shipment.Waybill')
            ?? null;

        if (!$waybill) {
            Log::info('Delhivery webhook received without AWB', ['payload' => $payload]);

            return null;
        }

        $shipment = OrderShipment::where('waybill', $waybill)->first();

        if (!$shipment) {
            Log::info('Delhivery webhook received for unknown AWB', [
                'waybill' => $waybill,
                'payload' => $payload,
            ]);

            return null;
        }

        $rawStatus = $payload['status']
            ?? $payload['scan']
            ?? $payload['Scan']
            ?? Arr::get($payload, 'Shipment.Status.Status')
            ?? null;

        $shipment->fill([
            'shipment_status' => $this->normalizeStatus($rawStatus),
            'raw_status' => $rawStatus,
            'status_location' => $payload['location'] ?? Arr::get($payload, 'Shipment.Status.StatusLocation'),
            'status_instructions' => $payload['instructions'] ?? Arr::get($payload, 'Shipment.Status.Instructions'),
            'tracking_payload' => array_merge($shipment->tracking_payload ?? [], ['webhook' => $payload]),
            'last_synced_at' => now(),
        ])->save();

        return $shipment;
    }

    public function trackingData(OrderShipment $shipment): array
    {
        return [
            'provider' => $shipment->provider,
            'waybill' => $shipment->waybill,
            'shipment_status' => $shipment->shipment_status,
            'raw_status' => $shipment->raw_status,
            'status_location' => $shipment->status_location,
            'status_instructions' => $shipment->status_instructions,
            'courier_tracking_url' => $shipment->courier_tracking_url,
            'last_synced_at' => $shipment->last_synced_at?->format('Y-m-d H:i:s'),
            'tracking' => $this->trackingTimeline($shipment->tracking_payload ?? []),
        ];
    }

    public function shipmentSummary(?OrderShipment $shipment): array
    {
        return [
            'provider' => OrderShipment::PROVIDER_DELHIVERY,
            'waybill' => $shipment?->waybill,
            'courier_tracking_url' => $shipment?->courier_tracking_url,
            'shipment_status' => $shipment?->shipment_status ?? OrderShipment::STATUS_NOT_CREATED,
            'raw_status' => $shipment?->raw_status,
            'last_synced_at' => $shipment?->last_synced_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function buildCreatePayload(Order $order): array
    {
        $address = $order->shipping_address ?? [];
        $weight = $this->calculateWeight($order);

        return [
            'shipments' => [
                [
                    'name' => $address['name'] ?? $order->user?->name ?? 'Customer',
                    'add' => $this->formatAddress($address),
                    'pin' => $address['postal_code'] ?? null,
                    'city' => $address['city'] ?? null,
                    'state' => $address['state'] ?? null,
                    'country' => $address['country'] ?? 'India',
                    'phone' => $address['phone'] ?? null,
                    'order' => $order->order_number,
                    'payment_mode' => $this->paymentMode($order),
                    'cod_amount' => number_format($this->codAmount($order), 2, '.', ''),
                    'total_amount' => number_format((float) $order->total_amount, 2, '.', ''),
                    'products_desc' => $this->productDescription($order),
                    'quantity' => (string) $order->orderItems->sum('quantity'),
                    'weight' => (string) $weight,
                    'seller_gst_tin' => config('delhivery.seller_gst_tin'),
                    'hsn_code' => config('delhivery.default_hsn_code'),
                    'shipment_width' => (string) config('delhivery.default_width_cm', 10),
                    'shipment_height' => (string) config('delhivery.default_height_cm', 5),
                    'shipment_length' => (string) config('delhivery.default_length_cm', 10),
                ],
            ],
            'pickup_location' => [
                'name' => $this->requiredConfig('pickup_location', 'Delhivery pickup location is not configured'),
            ],
        ];
    }

    private function calculateWeight(Order $order): int
    {
        $missing = $order->orderItems
            ->filter(fn ($item) => empty($item->product?->weight_grams))
            ->pluck('product_name')
            ->unique()
            ->values();

        if ($missing->isNotEmpty()) {
            throw new DomainException('Product weight is missing for: ' . $missing->implode(', '));
        }

        return (int) $order->orderItems->sum(
            fn ($item) => (int) $item->product->weight_grams * (int) $item->quantity
        );
    }

    private function paymentMode(Order $order): string
    {
        return $order->payment_method === 'cod' ? 'COD' : 'Pre-paid';
    }

    private function codAmount(Order $order): float
    {
        return $order->payment_method === 'cod' ? (float) $order->total_amount : 0.0;
    }

    private function formatAddress(array $address): string
    {
        return collect([
            $address['address_line_1'] ?? null,
            $address['address_line_2'] ?? null,
            $address['landmark'] ?? null,
        ])->filter()->implode(', ');
    }

    private function productDescription(Order $order): string
    {
        return $order->orderItems
            ->map(fn ($item) => "{$item->product_name} x {$item->quantity}")
            ->implode(', ');
    }

    private function requiredConfig(string $key, string $message): string
    {
        $value = config("delhivery.{$key}");

        if (!$value) {
            throw new DomainException($message);
        }

        return $value;
    }

    private function extractWaybill(array $payload): ?string
    {
        return Arr::get($payload, 'packages.0.waybill')
            ?? Arr::get($payload, 'packages.0.wbn')
            ?? Arr::get($payload, 'packages.0.Waybill')
            ?? $payload['waybill']
            ?? $payload['awb']
            ?? null;
    }

    private function extractProviderReference(array $payload): ?string
    {
        return Arr::get($payload, 'packages.0.refnum')
            ?? Arr::get($payload, 'packages.0.reference_number')
            ?? $payload['refnum']
            ?? null;
    }

    private function extractDelhiveryOrderId(array $payload): ?string
    {
        return Arr::get($payload, 'packages.0.order')
            ?? Arr::get($payload, 'packages.0.order_id')
            ?? $payload['order_id']
            ?? null;
    }

    private function extractRawStatus(array $payload): ?string
    {
        return Arr::get($payload, 'packages.0.status')
            ?? Arr::get($payload, 'ShipmentData.0.Shipment.Status.Status')
            ?? $payload['status']
            ?? null;
    }

    private function latestTrackingScan(array $payload): array
    {
        $scans = Arr::get($payload, 'ShipmentData.0.Shipment.Scans', []);

        if (!$scans) {
            return [];
        }

        $latest = end($scans);

        return $latest['ScanDetail'] ?? $latest ?? [];
    }

    private function trackingTimeline(array $payload): array
    {
        $scans = Arr::get($payload, 'ShipmentData.0.Shipment.Scans', []);

        return collect($scans)->map(function (array $scan) {
            $detail = $scan['ScanDetail'] ?? $scan;

            return [
                'status' => $detail['Scan'] ?? $detail['status'] ?? null,
                'location' => $detail['ScannedLocation'] ?? $detail['location'] ?? null,
                'instructions' => $detail['Instructions'] ?? $detail['instructions'] ?? null,
                'date_time' => $detail['ScanDateTime'] ?? $detail['date_time'] ?? null,
            ];
        })->values()->all();
    }

    private function normalizeStatus(?string $rawStatus): string
    {
        $status = strtolower((string) $rawStatus);

        return match (true) {
            str_contains($status, 'delivered') => OrderShipment::STATUS_DELIVERED,
            str_contains($status, 'out for delivery') => OrderShipment::STATUS_OUT_FOR_DELIVERY,
            str_contains($status, 'rto') || str_contains($status, 'return') => OrderShipment::STATUS_RTO,
            str_contains($status, 'cancel') => OrderShipment::STATUS_CANCELLED,
            str_contains($status, 'picked') => OrderShipment::STATUS_PICKED_UP,
            str_contains($status, 'pickup scheduled') => OrderShipment::STATUS_PICKUP_SCHEDULED,
            str_contains($status, 'pickup') => OrderShipment::STATUS_PICKUP_PENDING,
            str_contains($status, 'manifest') => OrderShipment::STATUS_MANIFESTED,
            str_contains($status, 'transit') || str_contains($status, 'dispatched') => OrderShipment::STATUS_IN_TRANSIT,
            default => OrderShipment::STATUS_IN_TRANSIT,
        };
    }
}
