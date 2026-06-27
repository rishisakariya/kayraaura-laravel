<?php

namespace App\Services\Delhivery;

use App\Models\DelhiverySetting;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\ShipmentStatusHistory;
use App\Jobs\ScheduleDelhiveryPickupJob;
use App\Services\PdfMerger;
use App\Services\Shipping\OrderShipmentLifecycleService;
use App\Support\PublicStorage;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DelhiveryShipmentService
{
    private ?DelhiverySetting $settings = null;

    public function __construct(
        private readonly DelhiveryClient $client,
        private readonly OrderShipmentLifecycleService $lifecycle,
        private readonly PdfMerger $pdfMerger,
        private readonly DelhiveryShipmentErrorClassifier $errorClassifier,
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
        $payload = null;
        $responsePayload = null;

        try {
            $shipment = DB::transaction(function () use (&$order, &$payload) {
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
                $this->ensureManifestedState($shipment);
                $this->schedulePickupIfNeeded($shipment->refresh());

                return $shipment;
            }

            $payload = $shipment->request_payload ?? $this->buildCreatePayload($order);

            $this->logShipmentCreation($order, 'create_request', [
                'payload' => $payload,
            ]);

            $responsePayload = $this->client->createShipment($payload);
            $shipment->fill(['response_payload' => $responsePayload])->save();

            $this->logShipmentCreation($order, 'create_response', [
                'response' => $responsePayload,
                'parsed_waybill' => $this->extractWaybill($responsePayload),
            ]);

            if ($responseError = $this->extractCreateError($responsePayload)) {
                if ($recovered = $this->recoverFromOrderReference($order, $shipment, $responsePayload)) {
                    return $recovered;
                }

                throw new DomainException($responseError);
            }

            $waybill = $this->extractWaybill($responsePayload);

            if (!$waybill) {
                if ($recovered = $this->recoverFromOrderReference($order, $shipment, $responsePayload)) {
                    return $recovered;
                }

                throw new DomainException($this->missingWaybillMessage($responsePayload));
            }

            $shipment = $this->applyManifestFromCreateResponse($shipment, $responsePayload, $waybill);
            $this->schedulePickupIfNeeded($shipment->refresh());

            $this->logShipmentCreation($order, 'manifested', [
                'waybill' => $shipment->waybill,
                'shipment_status' => $shipment->shipment_status,
            ]);

            return $shipment;
        } catch (\Throwable $e) {
            if ($shipment && ($recovered = $this->recoverFromOrderReference($order, $shipment, $responsePayload))) {
                $this->logShipmentCreation($order, 'recovered_after_error', [
                    'waybill' => $recovered->waybill,
                    'original_error' => $e->getMessage(),
                ]);

                return $recovered;
            }

            if ($shipment) {
                if ($this->errorClassifier->isRetryable($e)) {
                    $this->markShipmentRetryPending($shipment, $e);
                } else {
                    $this->markShipmentPermanentFailed($shipment, $e);
                }
            }

            $this->logShipmentCreation($order, 'failed', [
                'error' => $e->getMessage(),
                'retryable' => $this->errorClassifier->isRetryable($e),
                'recoverable_duplicate' => $this->errorClassifier->isRecoverableDuplicate($e->getMessage()),
                'request_payload' => $payload,
                'response_payload' => $responsePayload,
            ]);

            throw $e;
        }
    }

    public function reconcileOrderShipment(int|Order $order): bool
    {
        $order = $order instanceof Order
            ? $order->loadMissing('shipment')
            : Order::with('shipment')->findOrFail($order);

        $shipment = $order->shipment;

        if (!$shipment || $shipment->provider !== OrderShipment::PROVIDER_DELHIVERY) {
            return false;
        }

        if ($order->status === 'cancelled') {
            return false;
        }

        $statusBefore = $shipment->shipment_status;

        if ($shipment->hasWaybill() && $shipment->shipment_status === OrderShipment::STATUS_FAILED) {
            $this->ensureManifestedState($shipment);

            if ($shipment->refresh()->shipment_status !== OrderShipment::STATUS_FAILED) {
                return true;
            }

            try {
                $this->syncShipment($shipment->refresh());
            } catch (\Throwable) {
                $this->ensureManifestedState($shipment->refresh());
            }

            if ($shipment->refresh()->shipment_status !== OrderShipment::STATUS_FAILED) {
                return true;
            }
        }

        if ($shipment->hasWaybill()
            && filled($shipment->failed_reason)
            && $shipment->shipment_status !== OrderShipment::STATUS_FAILED) {
            $shipment->withAuditSource(ShipmentStatusHistory::SOURCE_SYSTEM)->fill([
                'failed_reason' => null,
                'raw_status' => $shipment->raw_status ?? 'Manifested',
            ])->save();

            return true;
        }

        if ($shipment->hasWaybill() && !in_array($shipment->shipment_status, [
            OrderShipment::STATUS_FAILED,
            OrderShipment::STATUS_RETRY_PENDING,
            OrderShipment::STATUS_NOT_CREATED,
        ], true)) {
            return $statusBefore !== $shipment->shipment_status;
        }

        if ($this->recoverFromOrderReference($order, $shipment) !== null) {
            return true;
        }

        return false;
    }

    public function reconcileFailedShipments(?int $orderId = null, ?string $orderNumber = null): int
    {
        $reconciled = 0;

        $query = OrderShipment::needsDelhiveryReconciliation()->with('order');

        if ($orderId) {
            $query->where('order_id', $orderId);
        }

        if ($orderNumber) {
            $query->whereHas('order', fn ($q) => $q->where('order_number', $orderNumber));
        }

        $query->chunkById(50, function ($shipments) use (&$reconciled) {
            foreach ($shipments as $shipment) {
                if ($shipment->order && $this->reconcileOrderShipment($shipment->order)) {
                    $reconciled++;
                }
            }
        });

        return $reconciled;
    }

    public function cancelShipmentAndVerify(
        OrderShipment $shipment,
        string $source = ShipmentStatusHistory::SOURCE_SYSTEM,
    ): OrderShipment {
        $shipment = $this->cancelShipment($shipment, $source);

        if ($shipment->shipment_status !== OrderShipment::STATUS_CANCELLED || !$shipment->hasWaybill()) {
            return $shipment;
        }

        try {
            $tracking = $this->client->trackShipment($shipment->waybill);
            $rawStatus = strtolower((string) ($this->extractRawStatus($tracking) ?? ''));

            if (!str_contains($rawStatus, 'cancel')) {
                Log::warning('Delhivery cancellation requested but tracking is not cancelled yet', [
                    'shipment_id' => $shipment->id,
                    'waybill' => $shipment->waybill,
                    'raw_status' => $rawStatus,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Delhivery cancellation verification tracking failed', [
                'shipment_id' => $shipment->id,
                'waybill' => $shipment->waybill,
                'error' => $e->getMessage(),
            ]);
        }

        return $shipment;
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

    public function schedulePickupIfNeeded(OrderShipment $shipment): void
    {
        if (!config('delhivery.auto_schedule_pickup', true)) {
            return;
        }

        if ($shipment->provider !== OrderShipment::PROVIDER_DELHIVERY) {
            return;
        }

        if (!$shipment->hasWaybill() || filled($shipment->pickup_request_id)) {
            return;
        }

        if ($shipment->shipment_status !== OrderShipment::STATUS_MANIFESTED) {
            return;
        }

        $pickupLocation = $shipment->pickup_location ?: $this->delhiverySettings()->pickup_location;
        $schedule = $this->resolvePickupSchedule();
        $delaySeconds = max(0, (int) config('delhivery.pickup_batch_delay_seconds', 180));

        ScheduleDelhiveryPickupJob::dispatch($pickupLocation, $schedule['pickup_date'])
            ->delay(now()->addSeconds($delaySeconds));
    }

    public function processPickupBatch(string $pickupLocation, string $pickupDate, bool $force = false): void
    {
        if (!$force && !config('delhivery.auto_schedule_pickup', true)) {
            return;
        }

        $lock = Cache::lock("delhivery:pickup-batch:{$pickupLocation}:{$pickupDate}", 120);

        if (!$lock->get()) {
            return;
        }

        try {
            $schedule = $this->resolvePickupSchedule($pickupDate);

            $batchContext = DB::transaction(function () use ($pickupLocation, $pickupDate, $schedule) {
                $pendingShipments = OrderShipment::query()
                    ->where('provider', OrderShipment::PROVIDER_DELHIVERY)
                    ->where('pickup_location', $pickupLocation)
                    ->whereNotNull('waybill')
                    ->whereNull('pickup_request_id')
                    ->where('shipment_status', OrderShipment::STATUS_MANIFESTED)
                    ->lockForUpdate()
                    ->get();

                if ($pendingShipments->isEmpty()) {
                    return null;
                }

                $existingPickupId = OrderShipment::query()
                    ->where('provider', OrderShipment::PROVIDER_DELHIVERY)
                    ->where('pickup_location', $pickupLocation)
                    ->whereNotNull('pickup_request_id')
                    ->whereDate('pickup_requested_at', $pickupDate)
                    ->value('pickup_request_id');

                return [
                    'pending_shipment_ids' => $pendingShipments->pluck('id')->all(),
                    'existing_pickup_id' => $existingPickupId,
                    'schedule' => $schedule,
                    'package_count' => $pendingShipments->count(),
                ];
            });

            if ($batchContext === null) {
                return;
            }

            if ($batchContext['existing_pickup_id']) {
                $this->finalizePickupBatch(
                    $batchContext['pending_shipment_ids'],
                    (string) $batchContext['existing_pickup_id'],
                    $batchContext['schedule'],
                    null,
                    null,
                );

                Log::info('Delhivery shipments linked to existing pickup request', [
                    'pickup_location' => $pickupLocation,
                    'pickup_date' => $pickupDate,
                    'pickup_request_id' => $batchContext['existing_pickup_id'],
                    'shipment_count' => $batchContext['package_count'],
                ]);

                return;
            }

            $payload = [
                'pickup_location' => $this->requiredSetting(
                    $pickupLocation,
                    'Delhivery pickup location is not configured'
                ),
                'pickup_date' => $batchContext['schedule']['pickup_date'],
                'pickup_time' => $batchContext['schedule']['pickup_time'],
                'expected_package_count' => $batchContext['package_count'],
            ];

            $responsePayload = $this->client->createPickupRequest($payload);
            $pickupRequestId = $this->extractPickupRequestId($responsePayload);

            if (!$pickupRequestId) {
                throw new DomainException('Delhivery pickup request did not return a pickup_id');
            }

            $this->finalizePickupBatch(
                $batchContext['pending_shipment_ids'],
                $pickupRequestId,
                $batchContext['schedule'],
                $payload,
                $responsePayload,
            );

            Log::info('Delhivery pickup request created', [
                'pickup_location' => $pickupLocation,
                'pickup_date' => $pickupDate,
                'pickup_request_id' => $pickupRequestId,
                'shipment_count' => $batchContext['package_count'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Delhivery pickup request failed', [
                'pickup_location' => $pickupLocation,
                'pickup_date' => $pickupDate,
                'delhivery_env' => config('delhivery.env'),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<int>  $shipmentIds
     * @param  array{pickup_date: string, pickup_time: string}  $schedule
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>|null  $responsePayload
     */
    private function finalizePickupBatch(
        array $shipmentIds,
        string $pickupRequestId,
        array $schedule,
        ?array $payload,
        ?array $responsePayload,
    ): void {
        DB::transaction(function () use ($shipmentIds, $pickupRequestId, $schedule, $payload, $responsePayload): void {
            $shipments = OrderShipment::query()
                ->whereIn('id', $shipmentIds)
                ->whereNull('pickup_request_id')
                ->where('shipment_status', OrderShipment::STATUS_MANIFESTED)
                ->lockForUpdate()
                ->get();

            if ($shipments->isEmpty()) {
                return;
            }

            $this->markShipmentsPickupScheduled(
                $shipments,
                $pickupRequestId,
                $schedule,
                $payload,
                $responsePayload,
            );
        });
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

            Log::info('Delhivery tracking sync completed', [
                'shipment_id' => $shipment->id,
                'waybill' => $shipment->waybill,
                'shipment_status' => $normalizedStatus,
                'raw_status' => $rawStatus,
            ]);

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

            Log::info('Delhivery reverse tracking sync completed', [
                'shipment_id' => $shipment->id,
                'reverse_waybill' => $shipment->reverse_waybill,
                'reverse_status' => $normalizedStatus,
                'raw_status' => $rawStatus,
            ]);

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

            Log::info('Delhivery shipment cancelled', [
                'shipment_id' => $shipment->id,
                'order_id' => $shipment->order_id,
                'waybill' => $shipment->waybill,
                'source' => $source,
            ]);

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

    private function markOrderCancelled(OrderShipment $shipment): void
    {
        $order = $shipment->order()->first();

        if (!$order || in_array($order->status, ['cancelled', 'delivered', 'return_requested', 'returned'], true)) {
            return;
        }

        $order->status = 'cancelled';
        $order->save();
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

            PublicStorage::put($path, $pdfBinary);

            $shipment->fill([
                'shipping_label_url' => PublicStorage::url($path),
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

    /**
     * @param  iterable<int, OrderShipment>  $shipments
     * @param  int  $labelsPerPage  How many labels to place on each output page (1 = one per page).
     */
    public function generateMergedShippingLabels(iterable $shipments, int $labelsPerPage = 1): string
    {
        $labelsPerPage = max(1, $labelsPerPage);
        $shipments = collect($shipments)->values();

        if ($shipments->isEmpty()) {
            throw new DomainException('No shipments were provided');
        }

        foreach ($shipments as $shipment) {
            if ($shipment->provider !== OrderShipment::PROVIDER_DELHIVERY) {
                throw new DomainException("Shipment for order {$shipment->order_id} is not a Delhivery shipment");
            }

            if (!$shipment->hasWaybill()) {
                throw new DomainException("Shipment for order {$shipment->order_id} does not have an AWB yet");
            }
        }

        if ($shipments->count() > 30) {
            throw new DomainException('Cannot generate labels for more than 30 shipments at once');
        }

        if ($shipments->count() === 1 && $labelsPerPage === 1) {
            $shipment = $this->generateShippingLabel($shipments->first());
            $storagePath = PublicStorage::diskPath($shipment->shipping_label_url);

            if (!$storagePath || !PublicStorage::exists($storagePath)) {
                throw new DomainException("Label PDF file was not found for AWB {$shipment->waybill}");
            }

            return PublicStorage::get($storagePath);
        }

        // For multi-up grids we must use the per-waybill (4R) labels, which are tightly
        // cropped to the label artwork. The bulk packing-slip links can come back on a
        // larger page with the label only in the top portion, which scales down to a tiny
        // label with lots of empty space when packed into a grid cell.
        if ($labelsPerPage > 1) {
            return $this->mergeIndividualShippingLabels($shipments, $labelsPerPage);
        }

        $waybills = $shipments->pluck('waybill')->map(fn ($waybill) => (string) $waybill)->all();

        try {
            $payload = $this->client->shippingLabelPdf($waybills);
            $linksByWaybill = $this->extractPdfDownloadLinksByWaybill($payload);

            // Delhivery's packing slip API returns one PDF link per package (waybill),
            // not a single merged document. Download each label and merge them so the
            // resulting PDF contains a page for every requested shipment.
            $orderedLinks = collect($waybills)
                ->map(fn (string $waybill) => $linksByWaybill[$waybill] ?? null)
                ->filter()
                ->values();

            if ($orderedLinks->count() === $shipments->count()) {
                $pdfBinaries = $orderedLinks
                    ->map(fn (string $link) => $this->client->downloadBinary($link))
                    ->all();

                return $this->pdfMerger->mergeBinaries($pdfBinaries, $labelsPerPage);
            }

            Log::info('Delhivery bulk label did not return a link for every waybill, merging individually', [
                'waybills' => $waybills,
                'links_found' => $orderedLinks->count(),
                'expected' => $shipments->count(),
            ]);
        } catch (\Throwable $e) {
            Log::info('Delhivery bulk label request failed, falling back to individual labels', [
                'waybills' => $waybills,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->mergeIndividualShippingLabels($shipments, $labelsPerPage);
    }

    /**
     * @param  Collection<int, OrderShipment>  $shipments
     */
    private function mergeIndividualShippingLabels(Collection $shipments, int $labelsPerPage = 1): string
    {
        $binaries = [];

        foreach ($shipments as $shipment) {
            $shipment = $this->generateShippingLabel($shipment);
            $storagePath = PublicStorage::diskPath($shipment->shipping_label_url);

            if (!$storagePath || !PublicStorage::exists($storagePath)) {
                throw new DomainException("Label PDF file was not found for AWB {$shipment->waybill}");
            }

            $binary = PublicStorage::get($storagePath);

            // When packing several labels per page, trim each label to its artwork so
            // blank areas of the carrier's page don't shrink the label inside its cell.
            if ($labelsPerPage > 1) {
                $binary = $this->pdfMerger->cropToContent($binary);
            }

            $binaries[] = $binary;
        }

        return $this->pdfMerger->mergeBinaries($binaries, $labelsPerPage);
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

            Log::info('Delhivery reverse pickup created', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'shipment_id' => $shipment->id,
                'forward_waybill' => $shipment->waybill,
                'reverse_waybill' => $waybill,
            ]);

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

            Log::info('Delhivery webhook processed for reverse shipment', [
                'shipment_id' => $shipment->id,
                'order_id' => $shipment->order_id,
                'reverse_waybill' => $waybill,
                'reverse_status' => $normalizedStatus,
            ]);

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

        Log::info('Delhivery webhook processed for forward shipment', [
            'shipment_id' => $shipment->id,
            'order_id' => $shipment->order_id,
            'waybill' => $waybill,
            'shipment_status' => $normalizedStatus,
            'raw_status' => $rawStatus,
        ]);

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

    private function labelStoragePath(?string $labelUrl): ?string
    {
        return PublicStorage::diskPath($labelUrl);
    }

    /**
     * Map each package's PDF download link by its waybill number.
     *
     * @return array<string, string>
     */
    private function extractPdfDownloadLinksByWaybill(array $payload): array
    {
        $packages = Arr::get($payload, 'packages', []);

        if (!is_array($packages)) {
            return [];
        }

        $links = [];

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $waybill = $package['waybill']
                ?? $package['wbn']
                ?? $package['Waybill']
                ?? null;

            $link = $package['pdf_download_link']
                ?? $package['pdf_link']
                ?? $package['label_url']
                ?? null;

            if (filled($waybill) && filled($link)) {
                $links[(string) $waybill] = (string) $link;
            }
        }

        return $links;
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

    /**
     * @return array{pickup_date: string, pickup_time: string}
     */
    private function resolvePickupSchedule(?string $pickupDate = null): array
    {
        $now = now();
        $cutoff = (string) config('delhivery.pickup_same_day_cutoff', '14:00');
        $defaultPickupTime = $this->normalizePickupTime((string) config('delhivery.pickup_time', '14:00:00'));

        if (!$pickupDate) {
            $pickupDate = $now->format('H:i') < $cutoff
                ? $now->format('Y-m-d')
                : $now->copy()->addDay()->format('Y-m-d');
        }

        $scheduledAt = $now->copy()->setDateFrom(
            (int) substr($pickupDate, 0, 4),
            (int) substr($pickupDate, 5, 2),
            (int) substr($pickupDate, 8, 2),
        )->setTimeFromTimeString($defaultPickupTime);

        if ($scheduledAt->lte($now)) {
            if ($pickupDate === $now->format('Y-m-d') && $now->format('H:i') < $cutoff) {
                $scheduledAt = $now->copy()->addMinutes(30)->second(0);
            } else {
                $pickupDate = $now->copy()->addDay()->format('Y-m-d');
                $scheduledAt = $now->copy()->addDay()->setTime(10, 0, 0);
            }
        }

        return [
            'pickup_date' => $pickupDate,
            'pickup_time' => $scheduledAt->format('H:i:s'),
        ];
    }

    private function normalizePickupTime(string $pickupTime): string
    {
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $pickupTime) === 1) {
            return $pickupTime;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $pickupTime) === 1) {
            return $pickupTime . ':00';
        }

        return '14:00:00';
    }

    /**
     * @param  Collection<int, OrderShipment>  $shipments
     * @param  array{pickup_date: string, pickup_time: string}  $schedule
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>|null  $responsePayload
     */
    private function markShipmentsPickupScheduled(
        Collection $shipments,
        string $pickupRequestId,
        array $schedule,
        ?array $payload,
        ?array $responsePayload,
    ): void {
        foreach ($shipments as $shipment) {
            $shipment->withAuditSource(ShipmentStatusHistory::SOURCE_SYSTEM)->fill([
                'pickup_request_id' => $pickupRequestId,
                'pickup_requested_at' => now(),
                'pickup_request_payload' => $payload ?? [
                    'pickup_location' => $shipment->pickup_location,
                    'pickup_date' => $schedule['pickup_date'],
                    'pickup_time' => $schedule['pickup_time'],
                    'linked_to_existing_pickup' => true,
                ],
                'pickup_request_response' => $responsePayload,
                'shipment_status' => OrderShipment::STATUS_PICKUP_SCHEDULED,
                'raw_status' => 'Pickup Scheduled',
            ])->save();
        }
    }

    private function extractPickupRequestId(array $payload): ?string
    {
        $pickupId = Arr::get($payload, 'pickup_id')
            ?? Arr::get($payload, 'pickup_request_id')
            ?? Arr::get($payload, 'data.pickup_id');

        if ($pickupId === null || $pickupId === '') {
            return null;
        }

        return (string) $pickupId;
    }

    private function recoverFromOrderReference(
        Order $order,
        OrderShipment $shipment,
        ?array $createResponse = null,
    ): ?OrderShipment {
        $trackingPayload = $this->client->trackByOrderReference($order->order_number);
        $rawStatus = strtolower((string) ($this->extractRawStatus($trackingPayload) ?? ''));

        if ($rawStatus !== '' && str_contains($rawStatus, 'cancel')) {
            return null;
        }

        $waybill = $this->extractWaybillFromTrackingPayload($trackingPayload);

        if (!$waybill && $shipment->hasWaybill()) {
            try {
                $trackingPayload = $this->client->trackShipment($shipment->waybill);
                $rawStatus = strtolower((string) ($this->extractRawStatus($trackingPayload) ?? ''));

                if ($rawStatus === '' || !str_contains($rawStatus, 'cancel')) {
                    $waybill = $shipment->waybill;
                }
            } catch (\Throwable) {
                $waybill = $shipment->waybill;
            }
        }

        if (!$waybill && is_array($createResponse)) {
            $waybill = $this->extractWaybill($createResponse);
        }

        if (!$waybill) {
            return null;
        }

        if ($shipment->waybill && $shipment->waybill !== $waybill) {
            Log::warning('Delhivery recovery AWB differs from local shipment AWB', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'local_waybill' => $shipment->waybill,
                'recovered_waybill' => $waybill,
            ]);
        }

        $shipment = $this->applyManifestFromCreateResponse(
            $shipment,
            $createResponse ?? ['recovered_from' => 'order_reference', 'tracking' => $trackingPayload],
            $waybill,
        );

        $this->schedulePickupIfNeeded($shipment->refresh());

        $this->logShipmentCreation($order, 'recovered_from_delhivery', [
            'waybill' => $shipment->waybill,
            'shipment_status' => $shipment->shipment_status,
        ]);

        return $shipment;
    }

    private function applyManifestFromCreateResponse(
        OrderShipment $shipment,
        array $responsePayload,
        string $waybill,
    ): OrderShipment {
        $shipment->withAuditSource(ShipmentStatusHistory::SOURCE_SYSTEM)->fill([
            'waybill' => $waybill,
            'provider_reference' => $this->extractProviderReference($responsePayload),
            'delhivery_order_id' => $this->extractDelhiveryOrderId($responsePayload),
            'shipment_status' => OrderShipment::STATUS_MANIFESTED,
            'raw_status' => $this->extractRawStatus($responsePayload) ?? 'Manifested',
            'courier_tracking_url' => "https://www.delhivery.com/track/package/{$waybill}",
            'manifested_at' => $shipment->manifested_at ?? now(),
            'response_payload' => array_merge($shipment->response_payload ?? [], [
                'create' => $responsePayload,
            ]),
            'failed_reason' => null,
        ])->save();

        return $shipment;
    }

    private function ensureManifestedState(OrderShipment $shipment): void
    {
        if (!$shipment->hasWaybill()) {
            return;
        }

        if (in_array($shipment->shipment_status, [
            OrderShipment::STATUS_FAILED,
            OrderShipment::STATUS_RETRY_PENDING,
            OrderShipment::STATUS_NOT_CREATED,
        ], true)) {
            $shipment->withAuditSource(ShipmentStatusHistory::SOURCE_SYSTEM)->fill([
                'shipment_status' => OrderShipment::STATUS_MANIFESTED,
                'raw_status' => $shipment->raw_status ?? 'Manifested',
                'failed_reason' => null,
                'manifested_at' => $shipment->manifested_at ?? now(),
            ])->save();
        }
    }

    private function markShipmentRetryPending(OrderShipment $shipment, \Throwable $e): void
    {
        $shipment->withAuditSource(ShipmentStatusHistory::SOURCE_SYSTEM)->fill([
            'shipment_status' => OrderShipment::STATUS_RETRY_PENDING,
            'failed_reason' => $e->getMessage(),
        ])->save();
    }

    private function markShipmentPermanentFailed(OrderShipment $shipment, \Throwable $e): void
    {
        if ($shipment->hasWaybill()) {
            $this->ensureManifestedState($shipment);

            return;
        }

        $shipment->withAuditSource(ShipmentStatusHistory::SOURCE_SYSTEM)->fill([
            'shipment_status' => OrderShipment::STATUS_FAILED,
            'failed_reason' => $e->getMessage(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logShipmentCreation(Order $order, string $stage, array $context = []): void
    {
        Log::info('Delhivery shipment creation trace', array_merge([
            'stage' => $stage,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
        ], $context));
    }

    private function extractWaybillFromTrackingPayload(array $payload): ?string
    {
        $awb = Arr::get($payload, 'ShipmentData.0.Shipment.AWB')
            ?? Arr::get($payload, 'ShipmentData.0.Shipment.Waybill')
            ?? Arr::get($payload, 'ShipmentData.0.Shipment.waybill');

        if ($awb === null || $awb === '') {
            return null;
        }

        return (string) $awb;
    }
}
