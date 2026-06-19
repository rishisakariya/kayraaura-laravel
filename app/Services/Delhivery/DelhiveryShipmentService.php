<?php

namespace App\Services\Delhivery;

use App\Models\DelhiverySetting;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\ShipmentStatusHistory;
use App\Services\Shipping\OrderShipmentLifecycleService;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DelhiveryShipmentService
{
    private ?DelhiverySetting $settings = null;

    public function __construct(
        private readonly DelhiveryClient $client,
        private readonly OrderShipmentLifecycleService $lifecycle,
    )
    {
    }

    public function isConfigured(): bool
    {
        if (config('delhivery.mock')) {
            return true;
        }

        return filled(config('delhivery.token'));
    }

    public function createShipment(Order $order): OrderShipment
    {
        $shipment = null;

        try {
            $shipment = DB::transaction(function () use (&$order) {
                $order = Order::with(['orderItems.product'])
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $shipment = OrderShipment::firstOrCreate(
                    ['order_id' => $order->id],
                    [
                        'provider' => OrderShipment::PROVIDER_DELHIVERY,
                        'shipment_status' => OrderShipment::STATUS_NOT_CREATED,
                        'pickup_location' => $this->delhiverySettings()->pickup_location,
                    ]
                );

                $shipment->refresh();

                if ($shipment->hasWaybill()) {
                    return $shipment;
                }

                $weight = $this->calculateWeight($order);
                $payload = $this->buildCreatePayload($order);

                $shipment->fill([
                    'provider' => OrderShipment::PROVIDER_DELHIVERY,
                    'shipment_status' => OrderShipment::STATUS_NOT_CREATED,
                    'pickup_location' => $this->delhiverySettings()->pickup_location,
                    'payment_mode' => $this->paymentMode($order),
                    'cod_amount' => $this->codAmount($order),
                    'weight_grams' => $weight,
                    'length_cm' => $this->delhiverySettings()->default_length_cm,
                    'width_cm' => $this->delhiverySettings()->default_width_cm,
                    'height_cm' => $this->delhiverySettings()->default_height_cm,
                    'request_payload' => $payload,
                    'failed_reason' => null,
                ])->save();

                return $shipment;
            });

            if ($shipment->hasWaybill()) {
                return $shipment;
            }

            $payload = $shipment->request_payload ?? $this->buildCreatePayload($order);
            $responsePayload = $this->client->createShipment($payload);
            $shipment->fill(['response_payload' => $responsePayload])->save();

            if ($responseError = $this->extractCreateError($responsePayload)) {
                throw new DomainException($responseError);
            }

            $waybill = $this->extractWaybill($responsePayload);

            if (!$waybill) {
                throw new DomainException($this->missingWaybillMessage($responsePayload));
            }

            $shipment->withAuditSource(ShipmentStatusHistory::SOURCE_SYSTEM)->fill([
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
            if ($shipment) {
                $shipment->withAuditSource(ShipmentStatusHistory::SOURCE_SYSTEM)->fill([
                    'shipment_status' => OrderShipment::STATUS_FAILED,
                    'failed_reason' => $e->getMessage(),
                ])->save();
            }

            Log::error('Delhivery shipment creation failed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function queuePlaceholder(Order $order): OrderShipment
    {
        return OrderShipment::firstOrCreate(
            ['order_id' => $order->id],
            [
                'provider' => OrderShipment::PROVIDER_DELHIVERY,
                'shipment_status' => OrderShipment::STATUS_NOT_CREATED,
                'pickup_location' => $this->delhiverySettings()->pickup_location,
            ]
        );
    }

    public function syncShipment(OrderShipment $shipment): OrderShipment
    {
        if (!$shipment->hasWaybill()) {
            return $shipment;
        }

        if (in_array($shipment->shipment_status, OrderShipment::TERMINAL_STATUSES, true)) {
            $this->lifecycle->applyForwardStatus($shipment, $shipment->shipment_status);

            return $shipment;
        }

        try {
            $payload = $this->client->trackShipment($shipment->waybill);
            $scan = $this->latestTrackingScan($payload);
            $rawStatus = $this->extractRawStatus($payload) ?? $scan['status'] ?? $scan['Scan'] ?? null;

            if (!$rawStatus) {
                throw new DomainException('Delhivery tracking response did not contain shipment status');
            }

            $normalizedStatus = $this->normalizeStatus($rawStatus);

            $shipment->withAuditSource(ShipmentStatusHistory::SOURCE_SYNC)->fill([
                'shipment_status' => $normalizedStatus,
                'raw_status' => $rawStatus,
                'status_location' => $this->extractStatusLocation($payload) ?? $scan['location'] ?? $scan['ScannedLocation'] ?? null,
                'status_instructions' => $this->extractStatusInstructions($payload) ?? $scan['instructions'] ?? $scan['Instructions'] ?? null,
                'tracking_payload' => $payload,
                'last_synced_at' => now(),
            ]);

            $this->applyForwardShipmentTimestamps($shipment, $normalizedStatus);
            $shipment->save();
            $this->lifecycle->applyForwardStatus($shipment, $normalizedStatus);

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

    public function syncReverseShipment(OrderShipment $shipment): OrderShipment
    {
        if (!$shipment->reverse_waybill) {
            return $shipment;
        }

        if ($shipment->reverse_status === OrderShipment::STATUS_DELIVERED) {
            $this->lifecycle->completeReturnIfReceived($shipment, OrderShipment::STATUS_DELIVERED);

            return $shipment;
        }

        if (in_array($shipment->reverse_status, [
            OrderShipment::STATUS_CANCELLED,
            OrderShipment::STATUS_FAILED,
            OrderShipment::STATUS_RTO,
        ], true)) {
            return $shipment;
        }

        try {
            $payload = $this->client->trackShipment($shipment->reverse_waybill);
            $scan = $this->latestTrackingScan($payload);
            $rawStatus = $this->extractRawStatus($payload) ?? $scan['status'] ?? $scan['Scan'] ?? null;

            if (!$rawStatus) {
                throw new DomainException('Delhivery reverse tracking response did not contain shipment status');
            }

            $normalizedStatus = $this->normalizeStatus($rawStatus);

            $shipment->withAuditSource(ShipmentStatusHistory::SOURCE_SYNC)->fill([
                'reverse_status' => $normalizedStatus,
                'reverse_response_payload' => array_merge($shipment->reverse_response_payload ?? [], [
                    'tracking' => $payload,
                ]),
                'last_synced_at' => now(),
            ])->save();

            $this->lifecycle->completeReturnIfReceived($shipment, $normalizedStatus);

            return $shipment;
        } catch (\Throwable $e) {
            $shipment->fill([
                'reverse_failed_reason' => $e->getMessage(),
                'last_synced_at' => now(),
            ])->save();

            Log::warning('Delhivery reverse tracking sync failed', [
                'shipment_id' => $shipment->id,
                'reverse_waybill' => $shipment->reverse_waybill,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function cancelShipment(OrderShipment $shipment, string $source = ShipmentStatusHistory::SOURCE_SYSTEM): OrderShipment
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

            if ($responseError = $this->extractDelhiveryFailure($payload)) {
                throw new DomainException($responseError);
            }

            $shipment->withAuditSource($source)->fill([
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

    public function generateShippingLabel(OrderShipment $shipment): OrderShipment
    {
        if (!$shipment->hasWaybill()) {
            throw new DomainException('Shipment AWB is required before label can be generated');
        }

        if ($shipment->shipping_label_url) {
            return $shipment;
        }

        try {
            $payload = $this->client->shippingLabelPdf($shipment->waybill);
            $pdfUrl = $this->extractPdfDownloadLink($payload);

            if (!$pdfUrl) {
                throw new DomainException('Delhivery did not return a shipping label PDF link for this AWB');
            }

            $pdfBinary = $this->client->downloadBinary($pdfUrl);
            $fileName = preg_replace('/[^A-Za-z0-9_\-]/', '-', $shipment->waybill) . '.pdf';
            $path = "shipping-labels/delhivery/{$fileName}";

            Storage::disk('public')->put($path, $pdfBinary);

            $shipment->fill([
                'shipping_label_url' => $this->publicStorageUrl($path),
                'response_payload' => array_merge($shipment->response_payload ?? [], [
                    'delhivery_label' => $payload,
                    'delhivery_label_pdf_url' => $pdfUrl,
                ]),
                'failed_reason' => null,
            ])->save();

            return $shipment;
        } catch (\Throwable $e) {
            $shipment->fill(['failed_reason' => $e->getMessage()])->save();

            Log::warning('Delhivery shipping label generation failed', [
                'shipment_id' => $shipment->id,
                'waybill' => $shipment->waybill,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function createReversePickup(Order $order): OrderShipment
    {
        $order = Order::with(['shipment', 'orderItems.product'])
            ->whereKey($order->id)
            ->firstOrFail();

        $shipment = $order->shipment;

        if (!$shipment || !$shipment->hasWaybill()) {
            throw new DomainException('Forward shipment AWB is required before return pickup can be created');
        }

        if ($shipment->reverse_waybill) {
            return $shipment;
        }

        try {
            $payload = $this->buildReversePickupPayload($order);

            $shipment->withAuditSource(ShipmentStatusHistory::SOURCE_SYSTEM)->fill([
                'reverse_status' => OrderShipment::STATUS_NOT_CREATED,
                'reverse_request_payload' => $payload,
                'reverse_failed_reason' => null,
            ])->save();

            $responsePayload = $this->client->createShipment($payload);
            $shipment->fill(['reverse_response_payload' => $responsePayload])->save();

            if ($responseError = $this->extractCreateError($responsePayload)) {
                throw new DomainException($responseError);
            }

            $waybill = $this->extractWaybill($responsePayload);

            if (!$waybill) {
                throw new DomainException($this->missingWaybillMessage($responsePayload));
            }

            $shipment->withAuditSource(ShipmentStatusHistory::SOURCE_SYSTEM)->fill([
                'reverse_waybill' => $waybill,
                'reverse_provider_reference' => $this->extractProviderReference($responsePayload),
                'reverse_status' => OrderShipment::STATUS_PICKUP_SCHEDULED,
                'reverse_tracking_url' => "https://www.delhivery.com/track/package/{$waybill}",
                'reverse_requested_at' => now(),
                'reverse_response_payload' => $responsePayload,
                'reverse_failed_reason' => null,
            ])->save();

            return $shipment;
        } catch (\Throwable $e) {
            $shipment->withAuditSource(ShipmentStatusHistory::SOURCE_SYSTEM)->fill([
                'reverse_status' => OrderShipment::STATUS_FAILED,
                'reverse_failed_reason' => $e->getMessage(),
            ])->save();

            Log::error('Delhivery reverse pickup creation failed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
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

        $shipment = OrderShipment::where('waybill', $waybill)
            ->orWhere('reverse_waybill', $waybill)
            ->first();

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

        $normalizedStatus = $this->normalizeStatus($rawStatus);

        if ($shipment->reverse_waybill === $waybill) {
            $shipment->withAuditSource(ShipmentStatusHistory::SOURCE_WEBHOOK)->fill([
                'reverse_status' => $normalizedStatus,
                'reverse_response_payload' => array_merge($shipment->reverse_response_payload ?? [], ['webhook' => $payload]),
                'last_synced_at' => now(),
            ])->save();

            $this->lifecycle->completeReturnIfReceived($shipment, $normalizedStatus);

            return $shipment;
        }

        $shipment->withAuditSource(ShipmentStatusHistory::SOURCE_WEBHOOK)->fill([
            'shipment_status' => $normalizedStatus,
            'raw_status' => $rawStatus,
            'status_location' => $payload['location'] ?? Arr::get($payload, 'Shipment.Status.StatusLocation'),
            'status_instructions' => $payload['instructions'] ?? Arr::get($payload, 'Shipment.Status.Instructions'),
            'tracking_payload' => array_merge($shipment->tracking_payload ?? [], ['webhook' => $payload]),
            'last_synced_at' => now(),
        ]);

        $this->applyForwardShipmentTimestamps($shipment, $normalizedStatus);
        $shipment->save();
        $this->lifecycle->applyForwardStatus($shipment, $normalizedStatus);

        return $shipment;
    }

    public function trackingData(OrderShipment $shipment): array
    {
        $shipment->loadMissing('statusHistories');

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
            'status_history' => ShipmentStatusHistory::formatForApi($shipment->statusHistories),
            'return' => [
                'waybill' => $shipment->reverse_waybill,
                'status' => $shipment->reverse_status,
                'tracking_url' => $shipment->reverse_tracking_url,
                'requested_at' => $shipment->reverse_requested_at?->format('Y-m-d H:i:s'),
                'failed_reason' => $shipment->reverse_failed_reason,
            ],
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
            'return' => [
                'waybill' => $shipment?->reverse_waybill,
                'status' => $shipment?->reverse_status,
                'tracking_url' => $shipment?->reverse_tracking_url,
                'requested_at' => $shipment?->reverse_requested_at?->format('Y-m-d H:i:s'),
            ],
        ];
    }

    private function buildCreatePayload(Order $order): array
    {
        $address = $order->shipping_address ?? [];
        $weight = $this->calculateWeight($order);
        $settings = $this->delhiverySettings();

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
                    'client' => $this->requiredSetting($settings->client_name, 'Delhivery client name is not configured'),
                    'payment_mode' => $this->paymentMode($order),
                    'cod_amount' => number_format($this->codAmount($order), 2, '.', ''),
                    'total_amount' => number_format((float) $order->total_amount, 2, '.', ''),
                    'products_desc' => $this->productDescription($order),
                    'quantity' => (string) $order->orderItems->sum('quantity'),
                    'weight' => (string) $weight,
                    'seller_gst_tin' => $this->sellerGstTin($settings),
                    'client_gst_tin' => $this->sellerGstTin($settings),
                    'hsn_code' => $this->defaultHsnCode($settings),
                    'invoice_reference' => $order->order_number,
                    'seller_name' => $this->requiredSetting($settings->client_name, 'Delhivery client name is not configured'),
                    'seller_inv' => $order->order_number,
                    'seller_add' => $this->formatAddress($address),
                    'shipping_mode' => 'Surface',
                    'address_type' => 'home',
                    'shipment_width' => (string) $settings->default_width_cm,
                    'shipment_height' => (string) $settings->default_height_cm,
                    'shipment_length' => (string) $settings->default_length_cm,
                ],
            ],
            'pickup_location' => [
                'name' => $this->requiredSetting($settings->pickup_location, 'Delhivery pickup location is not configured'),
            ],
        ];
    }

    private function buildReversePickupPayload(Order $order): array
    {
        $address = $order->shipping_address ?? [];
        $weight = $this->calculateWeight($order);
        $settings = $this->delhiverySettings();

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
                    'order' => "{$order->order_number}-RETURN",
                    'client' => $this->requiredSetting($settings->client_name, 'Delhivery client name is not configured'),
                    'payment_mode' => 'Pickup',
                    'cod_amount' => '0.00',
                    'total_amount' => number_format((float) $order->total_amount, 2, '.', ''),
                    'products_desc' => $this->productDescription($order),
                    'quantity' => (string) $order->orderItems->sum('quantity'),
                    'weight' => (string) $weight,
                    'seller_gst_tin' => $this->sellerGstTin($settings),
                    'client_gst_tin' => $this->sellerGstTin($settings),
                    'hsn_code' => $this->defaultHsnCode($settings),
                    'invoice_reference' => "{$order->order_number}-RETURN",
                    'seller_name' => $this->requiredSetting($settings->client_name, 'Delhivery client name is not configured'),
                    'seller_inv' => "{$order->order_number}-RETURN",
                    'seller_add' => $this->formatAddress($address),
                    'shipping_mode' => 'Surface',
                    'address_type' => 'home',
                    'shipment_width' => (string) $settings->default_width_cm,
                    'shipment_height' => (string) $settings->default_height_cm,
                    'shipment_length' => (string) $settings->default_length_cm,
                ],
            ],
            'pickup_location' => [
                'name' => $this->requiredSetting($settings->pickup_location, 'Delhivery pickup location is not configured'),
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

    private function sellerGstTin(DelhiverySetting $settings): string
    {
        return $this->requiredSetting(
            $settings->seller_gst_tin ?: config('delhivery.seller_gst_tin'),
            'Delhivery seller GST TIN is not configured'
        );
    }

    private function defaultHsnCode(DelhiverySetting $settings): string
    {
        return $this->requiredSetting(
            $settings->default_hsn_code ?: config('delhivery.default_hsn_code'),
            'Delhivery default HSN code is not configured'
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

    private function delhiverySettings(): DelhiverySetting
    {
        return $this->settings ??= DelhiverySetting::current();
    }

    private function requiredSetting(?string $value, string $message): string
    {
        if (!$value) {
            throw new DomainException($message);
        }

        return $value;
    }

    private function extractCreateError(array $payload): ?string
    {
        if ($responseError = $this->extractDelhiveryFailure($payload)) {
            return $responseError;
        }

        $packageStatus = Arr::get($payload, 'packages.0.status')
            ?? Arr::get($payload, 'packages.0.success');

        if ($packageStatus === false || strtolower((string) $packageStatus) === 'fail') {
            return $this->extractDelhiveryMessage($payload)
                ?? 'Delhivery rejected the shipment';
        }

        return null;
    }

    private function extractDelhiveryFailure(array $payload): ?string
    {
        if (
            ($payload['success'] ?? null) === false
            || ($payload['status'] ?? null) === false
            || ($payload['error'] ?? null) === true
        ) {
            return $this->extractDelhiveryMessage($payload)
                ?? 'Delhivery request was not successful';
        }

        return null;
    }

    private function missingWaybillMessage(array $payload): string
    {
        return $this->extractDelhiveryMessage($payload)
            ?? 'Delhivery did not return an AWB number';
    }

    private function extractDelhiveryMessage(array $payload): ?string
    {
        $messages = [
            Arr::get($payload, 'error.message'),
            Arr::get($payload, 'error.description'),
            Arr::get($payload, 'packages.0.remarks'),
            Arr::get($payload, 'packages.0.remark'),
            Arr::get($payload, 'packages.0.message'),
            Arr::get($payload, 'packages.0.error'),
            $payload['rmk'] ?? null,
            $payload['remarks'] ?? null,
            $payload['message'] ?? null,
            $payload['error'] ?? null,
        ];

        foreach ($messages as $message) {
            if (is_string($message) && trim($message) !== '') {
                return trim($message);
            }

            if (is_array($message)) {
                $flattened = collect(Arr::flatten($message))
                    ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                    ->map(fn ($value) => trim($value))
                    ->values();

                if ($flattened->isNotEmpty()) {
                    return $flattened->implode('; ');
                }
            }
        }

        return null;
    }

    private function extractPdfDownloadLink(array $payload): ?string
    {
        $packages = Arr::get($payload, 'packages', []);

        if (!is_array($packages)) {
            return null;
        }

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $link = $package['pdf_download_link']
                ?? $package['pdf_link']
                ?? $package['label_url']
                ?? null;

            if (filled($link)) {
                return (string) $link;
            }
        }

        return Arr::get($payload, 'pdf_download_link')
            ?? Arr::get($payload, 'pdf_link')
            ?? null;
    }

    private function publicStorageUrl(string $path): string
    {
        return url('/storage/' . ltrim($path, '/'));
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
            ?? Arr::get($payload, 'ShipmentData.0.Status.Status')
            ?? Arr::get($payload, 'Status.Status')
            ?? $payload['status']
            ?? null;
    }

    private function extractStatusLocation(array $payload): ?string
    {
        return Arr::get($payload, 'ShipmentData.0.Shipment.Status.StatusLocation')
            ?? Arr::get($payload, 'ShipmentData.0.Status.StatusLocation')
            ?? Arr::get($payload, 'Status.StatusLocation');
    }

    private function extractStatusInstructions(array $payload): ?string
    {
        return Arr::get($payload, 'ShipmentData.0.Shipment.Status.Instructions')
            ?? Arr::get($payload, 'ShipmentData.0.Status.Instructions')
            ?? Arr::get($payload, 'Status.Instructions');
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
            str_contains($status, 'out for delivery') || str_contains($status, 'dispatched') => OrderShipment::STATUS_OUT_FOR_DELIVERY,
            str_contains($status, 'rto') || str_contains($status, 'dto') || str_contains($status, 'return') => OrderShipment::STATUS_RTO,
            str_contains($status, 'cancel') => OrderShipment::STATUS_CANCELLED,
            str_contains($status, 'lost') => OrderShipment::STATUS_FAILED,
            str_contains($status, 'picked') => OrderShipment::STATUS_PICKED_UP,
            str_contains($status, 'pickup scheduled') => OrderShipment::STATUS_PICKUP_SCHEDULED,
            str_contains($status, 'pickup') => OrderShipment::STATUS_PICKUP_PENDING,
            str_contains($status, 'manifest') => OrderShipment::STATUS_MANIFESTED,
            str_contains($status, 'pending') || str_contains($status, 'open') || str_contains($status, 'scheduled') => OrderShipment::STATUS_PICKUP_PENDING,
            str_contains($status, 'transit') => OrderShipment::STATUS_IN_TRANSIT,
            default => OrderShipment::STATUS_IN_TRANSIT,
        };
    }

    private function applyForwardShipmentTimestamps(OrderShipment $shipment, string $normalizedStatus): void
    {
        if ($normalizedStatus === OrderShipment::STATUS_DELIVERED && !$shipment->delivered_at) {
            $shipment->delivered_at = now();
        }

        if ($normalizedStatus === OrderShipment::STATUS_RTO && !$shipment->rto_at) {
            $shipment->rto_at = now();
        }

        if ($normalizedStatus === OrderShipment::STATUS_CANCELLED && !$shipment->cancelled_at) {
            $shipment->cancelled_at = now();
        }
    }
}
