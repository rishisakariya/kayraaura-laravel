<?php

namespace App\Services\Shiprocket;

use App\Models\DelhiverySetting;
use App\Models\Order;
use App\Models\OrderShipment;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ShiprocketShipmentService
{
    public function __construct(private readonly ShiprocketClient $client)
    {
    }

    public function isConfigured(): bool
    {
        return filled(config('shiprocket.credentials.email')) && filled(config('shiprocket.credentials.password'));
    }

    public function createShipment(Order $order): OrderShipment
    {
        $shipment = null;

        try {
            $shipment = DB::transaction(function () use ($order, &$shipment) {
                $order = Order::with(['orderItems.product'])
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $shipment = OrderShipment::firstOrCreate(
                    ['order_id' => $order->id],
                    [
                        'provider' => OrderShipment::PROVIDER_SHIPROCKET,
                        'shipment_status' => OrderShipment::STATUS_NOT_CREATED,
                        'pickup_location' => (string) config('shiprocket.pickup_location'),
                    ]
                );

                $shipment->refresh();

                if ($shipment->hasWaybill()) {
                    return $shipment;
                }

                $weight = $this->calculateWeight($order);
                $payload = $this->buildCreateAdhocPayload($order, $weight);

                $shipment->fill([
                    'provider' => OrderShipment::PROVIDER_SHIPROCKET,
                    'shipment_status' => OrderShipment::STATUS_NOT_CREATED,
                    'pickup_location' => (string) config('shiprocket.pickup_location'),
                    'payment_mode' => $this->paymentMode($order),
                    'cod_amount' => $this->codAmount($order),
                    'weight_grams' => $weight,
                    'length_cm' => $this->dimensions()->default_length_cm,
                    'width_cm' => $this->dimensions()->default_width_cm,
                    'height_cm' => $this->dimensions()->default_height_cm,
                    'request_payload' => $payload,
                    'failed_reason' => null,
                ])->save();

                return $shipment;
            });

            if ($shipment->hasWaybill()) {
                return $shipment;
            }

            $payload = $shipment->request_payload ?? $this->buildCreateAdhocPayload($order, $this->calculateWeight($order));
            $createResponse = $this->client->createAdhocOrder($payload);

            $shiprocketShipmentId = $this->extractShiprocketShipmentId($createResponse);
            $shiprocketOrderId = $this->extractShiprocketOrderId($createResponse);

            if (!$shiprocketShipmentId) {
                throw new DomainException('Shiprocket did not return shipment id');
            }

            $courierId = $this->maybeInt(config('shiprocket.courier_id'));
            $assignResponse = $this->client->assignAwb((int) $shiprocketShipmentId, $courierId, false);
            $awbCode = $this->extractAwbCode($assignResponse);

            if (!$awbCode) {
                throw new DomainException('Shiprocket did not return AWB code');
            }

            $this->client->generatePickup((int) $shiprocketShipmentId);
            $this->client->generateManifest([(int) $shiprocketShipmentId]);

            $shipment->fill([
                'waybill' => $awbCode,
                'provider_reference' => (string) $shiprocketOrderId,
                'delhivery_order_id' => (string) $shiprocketShipmentId,
                'shipment_status' => OrderShipment::STATUS_MANIFESTED,
                'raw_status' => 'Manifested',
                'courier_tracking_url' => "https://shiprocket.co/tracking/{$awbCode}",
                'manifested_at' => now(),
                'response_payload' => array_merge($shipment->response_payload ?? [], [
                    'create' => $createResponse,
                    'assign' => $assignResponse,
                ]),
                'failed_reason' => null,
            ])->save();

            return $shipment;
        } catch (\Throwable $e) {
            if ($shipment) {
                $shipment->fill([
                    'shipment_status' => OrderShipment::STATUS_FAILED,
                    'failed_reason' => $e->getMessage(),
                ])->save();
            }

            Log::error('Shiprocket shipment creation failed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function syncShipment(OrderShipment $shipment): OrderShipment
    {
        if (!$shipment->hasWaybill() || in_array($shipment->shipment_status, OrderShipment::TERMINAL_STATUSES, true)) {
            return $shipment;
        }

        try {
            $payload = $this->client->trackAwb((string) $shipment->waybill);

            // API response: payload['tracking_data'] with 'shipment_status', 'current_status', 'shipment_track_activities'
            $trackingData = $payload['tracking_data'] ?? $payload;
            $latestScan = $this->latestShiprocketScan($trackingData);
            $rawStatus = (string) ($trackingData['shipment_status'] ?? $trackingData['current_status'] ?? '');
            $rawStatus = $rawStatus !== '' ? $rawStatus : ($latestScan['status'] ?? null);

            $normalizedStatus = $this->normalizeStatusFromScan($latestScan);

            $shipment->fill([
                'shipment_status' => $normalizedStatus,
                'raw_status' => $rawStatus,
                'status_location' => $latestScan['location'] ?? null,
                'status_instructions' => $latestScan['activity'] ?? null,
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

            Log::warning('Shiprocket tracking sync failed', [
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
            $payload = $this->client->cancelOrder((string) $shipment->provider_reference);

            $shipment->fill([
                'shipment_status' => OrderShipment::STATUS_CANCELLED,
                'raw_status' => (string) ($payload['message'] ?? 'Cancelled'),
                'cancelled_at' => now(),
                'response_payload' => array_merge($shipment->response_payload ?? [], ['cancel' => $payload]),
                'failed_reason' => null,
            ])->save();

            return $shipment;
        } catch (\Throwable $e) {
            $shipment->fill(['failed_reason' => $e->getMessage()])->save();

            Log::warning('Shiprocket shipment cancellation failed', [
                'shipment_id' => $shipment->id,
                'waybill' => $shipment->waybill,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function generateShippingLabel(OrderShipment $shipment): OrderShipment
    {
        if (!$shipment->hasWaybill()) {
            throw new DomainException('Shipment AWB is required before label can be generated');
        }

        if ($shipment->shipping_label_url) {
            return $shipment;
        }

        $shiprocketShipmentId = $shipment->delhivery_order_id;
        if (!$shiprocketShipmentId) {
            throw new DomainException('Shiprocket shipment id is required before label can be generated');
        }

        try {
            $labelResponse = $this->client->generateLabel([(int) $shiprocketShipmentId]);
            $pdfUrl = $this->extractPdfUrl($labelResponse);

            if (!$pdfUrl) {
                throw new DomainException('Shiprocket did not return a label PDF url');
            }

            $pdfResponse = Http::timeout(60)->get($pdfUrl);
            if (!$pdfResponse->successful()) {
                throw new DomainException('Unable to download Shiprocket label PDF');
            }

            $fileName = preg_replace('/[^A-Za-z0-9_\-]/', '-', (string) $shipment->waybill) . '.pdf';
            $path = "shipping-labels/shiprocket/{$fileName}";

            Storage::disk('public')->put($path, $pdfResponse->body());

            $shipment->fill([
                'shipping_label_url' => url('/storage/' . ltrim($path, '/')),
                'response_payload' => array_merge($shipment->response_payload ?? [], [
                    'label' => $labelResponse,
                ]),
                'failed_reason' => null,
            ])->save();

            return $shipment;
        } catch (\Throwable $e) {
            $shipment->fill(['failed_reason' => $e->getMessage()])->save();

            Log::warning('Shiprocket shipping label generation failed', [
                'shipment_id' => $shipment->id,
                'waybill' => $shipment->waybill,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function generateTestShippingLabel(Order $order): string
    {
        $order->loadMissing(['orderItems.product']);

        $waybill = 'TEST-SR-AWB-' . $order->id;

        $shipment = new OrderShipment([
            'provider' => OrderShipment::PROVIDER_SHIPROCKET,
            'waybill' => $waybill,
            'shipment_status' => OrderShipment::STATUS_MANIFESTED,
            'payment_mode' => $this->paymentMode($order),
            'cod_amount' => $this->codAmount($order),
            'weight_grams' => max(1, (int) $order->orderItems->sum(
                fn ($item) => (int) ($item->product?->weight_grams ?? 0) * (int) $item->quantity
            )),
            'length_cm' => $this->dimensions()->default_length_cm,
            'width_cm' => $this->dimensions()->default_width_cm,
            'height_cm' => $this->dimensions()->default_height_cm,
            'delhivery_order_id' => 'TEST-SHIPROCKET-SHIPMENT-' . $order->id,
        ]);
        $shipment->setRelation('order', $order);

        $package = [
            'awb' => $waybill,
        ];

        $path = "shipping-labels/test/{$waybill}.pdf";
        $pdf = Pdf::loadView('shipments.shiprocket-label', [
            'shipment' => $shipment,
            'order' => $order,
            'package' => $package,
            'generatedAt' => now(),
        ])->setPaper('a4');

        Storage::disk('public')->put($path, $pdf->output());

        return url('/storage/' . ltrim($path, '/'));
    }

    public function createReversePickup(Order $order): OrderShipment
    {
        $shipment = $order->shipment;

        if (!$shipment || !$shipment->hasWaybill()) {
            throw new DomainException('Forward shipment AWB is required before return pickup can be created');
        }

        if ($shipment->reverse_waybill) {
            return $shipment;
        }

        try {
            $weight = $this->calculateWeight($order);
            $payload = $this->buildCreateReturnPayload($order, $weight);

            $shipment->fill([
                'reverse_status' => OrderShipment::STATUS_NOT_CREATED,
                'reverse_request_payload' => $payload,
                'reverse_failed_reason' => null,
            ])->save();

            $returnCreateResponse = $this->client->createReturnOrder($payload);
            $shiprocketShipmentId = $this->extractShiprocketShipmentId($returnCreateResponse);
            $shiprocketOrderId = $this->extractShiprocketOrderId($returnCreateResponse);

            if (!$shiprocketShipmentId) {
                throw new DomainException('Shiprocket did not return return shipment id');
            }

            $courierId = $this->maybeInt(config('shiprocket.courier_id'));
            $assignResponse = $this->client->assignAwb((int) $shiprocketShipmentId, $courierId, true);
            $awbCode = $this->extractAwbCode($assignResponse);

            if (!$awbCode) {
                throw new DomainException('Shiprocket did not return return AWB code');
            }

            $this->client->generatePickup((int) $shiprocketShipmentId);
            $this->client->generateManifest([(int) $shiprocketShipmentId]);

            $shipment->fill([
                'reverse_waybill' => $awbCode,
                'reverse_provider_reference' => (string) $shiprocketOrderId,
                'reverse_status' => OrderShipment::STATUS_PICKUP_SCHEDULED,
                'reverse_tracking_url' => "https://shiprocket.co/tracking/{$awbCode}",
                'reverse_requested_at' => now(),
                'reverse_response_payload' => array_merge($shipment->reverse_response_payload ?? [], [
                    'create' => $returnCreateResponse,
                    'assign' => $assignResponse,
                ]),
                'reverse_failed_reason' => null,
            ])->save();

            return $shipment;
        } catch (\Throwable $e) {
            $shipment->fill([
                'reverse_status' => OrderShipment::STATUS_FAILED,
                'reverse_failed_reason' => $e->getMessage(),
            ])->save();

            Log::error('Shiprocket reverse pickup creation failed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function trackingData(OrderShipment $shipment): array
    {
        $trackingPayload = $shipment->tracking_payload ?? [];
        $latestScan = $this->latestShiprocketScan($trackingPayload);

        return [
            'provider' => $shipment->provider,
            'waybill' => $shipment->waybill,
            'shipment_status' => $shipment->shipment_status,
            'raw_status' => $shipment->raw_status,
            'status_location' => $shipment->status_location ?? ($latestScan['location'] ?? null),
            'status_instructions' => $shipment->status_instructions ?? ($latestScan['activity'] ?? null),
            'courier_tracking_url' => $shipment->courier_tracking_url,
            'last_synced_at' => $shipment->last_synced_at?->format('Y-m-d H:i:s'),
            'tracking' => $this->trackingTimelineFromShiprocketPayload($trackingPayload),
            'return' => [
                'waybill' => $shipment->reverse_waybill,
                'status' => $shipment->reverse_status,
                'tracking_url' => $shipment->reverse_tracking_url,
                'requested_at' => $shipment->reverse_requested_at?->format('Y-m-d H:i:s'),
                'failed_reason' => $shipment->reverse_failed_reason,
            ],
        ];
    }

    private function buildCreateAdhocPayload(Order $order, int $weightGrams): array
    {
        $address = $order->shipping_address ?? [];
        $delhiverySettings = $this->dimensions();

        $weightKg = max(0.1, $weightGrams / 1000);

        $pickupLocation = config('shiprocket.pickup_location');
        if (!$pickupLocation) {
            throw new DomainException('Shiprocket pickup location is not configured');
        }

        $paymentMethod = $order->payment_method === 'cod' ? 'COD' : 'Prepaid';

        $items = $order->orderItems->map(function ($item) {
            return [
                'name' => (string) $item->product_name,
                'sku' => (string) ($item->product_slug ?: $item->product_name),
                'units' => (int) $item->quantity,
                'selling_price' => (string) $item->price,
                'discount' => '',
                'tax' => '',
                'hsn' => '',
            ];
        })->values()->all();

        $billingAddress = $order->billing_address ?? $address;

        $channelId = $this->maybeInt(config('shiprocket.channel_id'));

        $payload = [
            'order_id' => (string) $order->order_number,
            'order_date' => now()->format('Y-m-d'),
            'pickup_location' => (string) $pickupLocation,
            'comment' => null,

            'billing_customer_name' => (string) ($billingAddress['name'] ?? $order->user?->name ?? 'Customer'),
            'billing_address' => $this->formatAddress($billingAddress),
            'billing_city' => $billingAddress['city'] ?? 'City',
            'billing_pincode' => (string) ($billingAddress['postal_code'] ?? $billingAddress['pincode'] ?? '000000'),
            'billing_state' => (string) ($billingAddress['state'] ?? 'State'),
            'billing_country' => (string) ($billingAddress['country'] ?? 'India'),
            'billing_email' => (string) ($billingAddress['email'] ?? $order->user?->email ?? ''),
            'billing_phone' => (string) ($billingAddress['phone'] ?? $address['phone'] ?? ''),

            'shipping_is_billing' => true,
            'shipping_customer_name' => (string) ($address['name'] ?? $order->user?->name ?? 'Customer'),
            'shipping_address' => $this->formatAddress($address),
            'shipping_city' => (string) ($address['city'] ?? 'City'),
            'shipping_pincode' => (string) ($address['postal_code'] ?? $address['pincode'] ?? '000000'),
            'shipping_state' => (string) ($address['state'] ?? 'State'),
            'shipping_country' => (string) ($address['country'] ?? 'India'),
            'shipping_email' => (string) ($address['email'] ?? $order->user?->email ?? ''),
            'shipping_phone' => (string) ($address['phone'] ?? ''),

            'order_items' => $items,
            'payment_method' => $paymentMethod,

            'shipping_charges' => 0,
            'giftwrap_charges' => 0,
            'transaction_charges' => 0,
            'total_discount' => 0,
            'sub_total' => (float) $order->subtotal,

            'weight' => (float) $weightKg,
            'length' => (float) $delhiverySettings->default_length_cm,
            'breadth' => (float) $delhiverySettings->default_width_cm,
            'height' => (float) $delhiverySettings->default_height_cm,
        ];

        if (!is_null($channelId)) {
            $payload['channel_id'] = $channelId;
        }

        return $payload;
    }

    private function buildCreateReturnPayload(Order $order, int $weightGrams): array
    {
        $pickup = $order->shipping_address ?? [];
        $seller = config('shiprocket.seller', []);

        $weightKg = max(0.1, $weightGrams / 1000);

        $paymentMethod = $order->payment_method === 'cod' ? 'COD' : 'Prepaid';

        $items = $order->orderItems->map(function ($item) {
            return [
                'name' => (string) $item->product_name,
                'sku' => (string) ($item->product_slug ?: $item->product_name),
                'units' => (int) $item->quantity,
                'selling_price' => (int) round((float) $item->price),
                'discount' => 0,
                'hsn' => '',
            ];
        })->values()->all();

        return [
            'order_id' => $order->order_number . '-RETURN',
            'order_date' => now()->format('Y-m-d'),
            'channel_id' => config('shiprocket.channel_id') ?? 0,

            'pickup_customer_name' => (string) ($pickup['name'] ?? $order->user?->name ?? 'Customer'),
            'pickup_address' => $this->formatAddress($pickup),
            'pickup_address_2' => null,
            'pickup_city' => (string) ($pickup['city'] ?? 'City'),
            'pickup_state' => (string) ($pickup['state'] ?? 'State'),
            'pickup_country' => (string) ($pickup['country'] ?? 'India'),
            'pickup_pincode' => (int) ($pickup['postal_code'] ?? $pickup['pincode'] ?? 0),
            'pickup_email' => (string) ($pickup['email'] ?? $order->user?->email ?? ''),
            'pickup_phone' => (string) ($pickup['phone'] ?? ''),

            'shipping_customer_name' => (string) ($seller['name'] ?? 'Seller'),
            'shipping_address' => $this->formatSellerAddress($seller),
            'shipping_address_2' => null,
            'shipping_city' => (string) ($seller['city'] ?? ($pickup['city'] ?? 'City')),
            'shipping_state' => (string) ($seller['state'] ?? ($pickup['state'] ?? 'State')),
            'shipping_country' => (string) ($seller['country'] ?? 'India'),
            'shipping_pincode' => (int) ($seller['postal_code'] ?? ($pickup['postal_code'] ?? 0)),
            'shipping_phone' => (string) ($seller['phone'] ?? ''),
            'shipping_email' => (string) ($seller['email'] ?? ''),

            'order_items' => $items,
            'payment_method' => $paymentMethod,
            'total_discount' => '0',
            'sub_total' => (int) round((float) $order->subtotal),

            'length' => (float) $this->dimensions()->default_length_cm,
            'breadth' => (float) $this->dimensions()->default_width_cm,
            'height' => (float) $this->dimensions()->default_height_cm,
            'weight' => (float) $weightKg,
        ];
    }

    private function dimensions(): DelhiverySetting
    {
        return DelhiverySetting::current();
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
        return implode(', ', collect([
            $address['address_line_1'] ?? null,
            $address['address_line_2'] ?? null,
            $address['landmark'] ?? null,
        ])->filter()->values()->all());
    }

    private function formatSellerAddress(array $seller): string
    {
        $line1 = (string) ($seller['address_line_1'] ?? '');
        $line2 = (string) ($seller['address_line_2'] ?? '');
        $landmark = (string) ($seller['landmark'] ?? '');

        return trim(implode(', ', array_filter([$line1, $line2, $landmark])));
    }

    private function maybeInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function extractShiprocketShipmentId(array $response): ?string
    {
        return (string) (
            Arr::get($response, 'shipment_id')
            ?? Arr::get($response, 'shipmentId')
            ?? Arr::get($response, 'data.shipment_id')
            ?? Arr::get($response, 'data.shipmentId')
            ?? Arr::get($response, 'data.shipment_id.0')
            ?? null
        );
    }

    private function extractShiprocketOrderId(array $response): ?string
    {
        return (string) (
            Arr::get($response, 'order_id')
            ?? Arr::get($response, 'orderId')
            ?? Arr::get($response, 'data.order_id')
            ?? Arr::get($response, 'data.orderId')
            ?? Arr::get($response, 'data.sr_order_id')
            ?? Arr::get($response, 'sr_order_id')
            ?? null
        );
    }

    private function extractAwbCode(array $response): ?string
    {
        return (string) (
            Arr::get($response, 'awb_code')
            ?? Arr::get($response, 'awbCode')
            ?? Arr::get($response, 'data.awb_code')
            ?? Arr::get($response, 'data.awbCode')
            ?? Arr::get($response, 'data.awb')
            ?? null
        );
    }

    private function latestShiprocketScan(array $payload): array
    {
        // API response uses 'shipment_track_activities'; webhook/mock uses 'scans'
        $scans = $payload['shipment_track_activities'] ?? $payload['scans'] ?? [];

        if (!is_array($scans) || $scans === []) {
            return [];
        }

        return end($scans) ?: [];
    }

    private function normalizeStatusFromScan(array $scan): string
    {
        $label = strtolower((string) ($scan['sr-status-label'] ?? $scan['sr-status'] ?? $scan['status'] ?? ''));
        $raw = $label;

        if ($raw === '') {
            return OrderShipment::STATUS_IN_TRANSIT;
        }

        return match (true) {
            str_contains($raw, 'manifest') => OrderShipment::STATUS_MANIFESTED,
            str_contains($raw, 'pickup scheduled') => OrderShipment::STATUS_PICKUP_SCHEDULED,
            str_contains($raw, 'pickup queued') => OrderShipment::STATUS_PICKUP_PENDING,
            str_contains($raw, 'pickup pending') => OrderShipment::STATUS_PICKUP_PENDING,
            str_contains($raw, 'picked up') => OrderShipment::STATUS_PICKED_UP,
            str_contains($raw, 'shipped') => OrderShipment::STATUS_IN_TRANSIT,
            str_contains($raw, 'out for delivery') => OrderShipment::STATUS_OUT_FOR_DELIVERY,
            str_contains($raw, 'delivered') => OrderShipment::STATUS_DELIVERED,
            str_contains($raw, 'rto') || str_contains($raw, 'return') => OrderShipment::STATUS_RTO,
            str_contains($raw, 'cancel') => OrderShipment::STATUS_CANCELLED,
            str_contains($raw, 'failed') => OrderShipment::STATUS_FAILED,
            str_contains($raw, 'in transit') => OrderShipment::STATUS_IN_TRANSIT,
            default => OrderShipment::STATUS_IN_TRANSIT,
        };
    }

    private function trackingTimelineFromShiprocketPayload(array $trackingPayload): array
    {
        $data = $trackingPayload['tracking_data'] ?? $trackingPayload;
        $scans = $data['shipment_track_activities'] ?? $data['scans'] ?? [];

        if (!is_array($scans)) {
            return [];
        }

        return collect($scans)->map(function (array $scan) {
            $status = $scan['sr-status-label'] ?? $scan['status'] ?? null;
            $location = $scan['location'] ?? null;
            $instructions = $scan['activity'] ?? null;
            $dateTime = $scan['date'] ?? null;

            return [
                'status' => $status,
                'location' => $location,
                'instructions' => $instructions,
                'date_time' => $dateTime,
            ];
        })->values()->all();
    }

    private function extractPdfUrl(array $payload): ?string
    {
        $urls = [];

        $walk = function (mixed $value) use (&$walk, &$urls) {
            if (is_string($value) && str_contains(strtolower($value), '.pdf')) {
                $urls[] = $value;
                return;
            }

            if (is_array($value)) {
                foreach ($value as $v) {
                    $walk($v);
                }
            }
        };

        $walk($payload);

        return $urls[0] ?? null;
    }
}

