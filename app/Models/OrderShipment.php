<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderShipment extends Model
{
    public const PROVIDER_DELHIVERY = 'delhivery';

    public const PROVIDER_SHIPROCKET = 'shiprocket';

    public const STATUS_NOT_CREATED = 'not_created';

    public const STATUS_MANIFESTED = 'manifested';

    public const STATUS_PICKUP_SCHEDULED = 'pickup_scheduled';

    public const STATUS_PICKUP_PENDING = 'pickup_pending';

    public const STATUS_PICKED_UP = 'picked_up';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_RTO = 'rto';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RETRY_PENDING = 'retry_pending';

    public const ACTIVE_STATUSES = [
        self::STATUS_MANIFESTED,
        self::STATUS_PICKUP_SCHEDULED,
        self::STATUS_PICKUP_PENDING,
        self::STATUS_PICKED_UP,
        self::STATUS_IN_TRANSIT,
        self::STATUS_OUT_FOR_DELIVERY,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_DELIVERED,
        self::STATUS_CANCELLED,
        self::STATUS_RTO,
        self::STATUS_FAILED,
    ];

    public const CANCELLABLE_STATUSES = [
        self::STATUS_NOT_CREATED,
        self::STATUS_MANIFESTED,
        self::STATUS_PICKUP_PENDING,
        self::STATUS_PICKUP_SCHEDULED,
    ];

    public ?string $auditSource = null;

    protected $fillable = [
        'order_id',
        'provider',
        'waybill',
        'reverse_waybill',
        'reverse_provider_reference',
        'reverse_status',
        'reverse_tracking_url',
        'reverse_requested_at',
        'reverse_failed_reason',
        'provider_reference',
        'delhivery_order_id',
        'shipment_status',
        'raw_status',
        'status_location',
        'status_instructions',
        'pickup_location',
        'payment_mode',
        'cod_amount',
        'courier_tracking_url',
        'weight_grams',
        'length_cm',
        'width_cm',
        'height_cm',
        'shipping_label_url',
        'last_synced_at',
        'manifested_at',
        'pickup_request_id',
        'pickup_requested_at',
        'pickup_request_payload',
        'pickup_request_response',
        'delivered_at',
        'cancelled_at',
        'rto_at',
        'failed_reason',
        'request_payload',
        'response_payload',
        'tracking_payload',
        'reverse_request_payload',
        'reverse_response_payload',
    ];

    protected $casts = [
        'cod_amount' => 'decimal:2',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'tracking_payload' => 'array',
        'last_synced_at' => 'datetime',
        'manifested_at' => 'datetime',
        'pickup_requested_at' => 'datetime',
        'pickup_request_payload' => 'array',
        'pickup_request_response' => 'array',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'rto_at' => 'datetime',
        'reverse_requested_at' => 'datetime',
        'reverse_request_payload' => 'array',
        'reverse_response_payload' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ShipmentStatusHistory::class, 'shipment_id');
    }

    public function withAuditSource(?string $source): self
    {
        $this->auditSource = $source;

        return $this;
    }

    public function scopeActiveForSync(Builder $query): Builder
    {
        return $query->where('provider', self::PROVIDER_DELHIVERY)
            ->whereNotNull('waybill')
            ->whereIn('shipment_status', self::ACTIVE_STATUSES);
    }

    public function scopeNeedsDelhiveryReconciliation(Builder $query): Builder
    {
        return $query->where('provider', self::PROVIDER_DELHIVERY)
            ->where(function (Builder $query) {
                $query->whereIn('shipment_status', [
                    self::STATUS_FAILED,
                    self::STATUS_RETRY_PENDING,
                    self::STATUS_NOT_CREATED,
                ])->orWhere(function (Builder $query) {
                    $query->where('shipment_status', self::STATUS_FAILED)
                        ->whereNotNull('waybill');
                });
            })
            ->whereHas('order', fn (Builder $query) => $query->where('status', '!=', 'cancelled'));
    }

    public function scopeNeedsDelhiverySync(Builder $query): Builder
    {
        return $query->where('provider', self::PROVIDER_DELHIVERY)
            ->where(function (Builder $query) {
                $query->where(function (Builder $query) {
                    $query->whereNotNull('waybill')
                        ->whereIn('shipment_status', self::ACTIVE_STATUSES);
                })->orWhere(function (Builder $query) {
                    $query->whereNotNull('reverse_waybill')
                        ->where(function (Builder $query) {
                            $query->whereNull('reverse_status')
                                ->orWhereIn('reverse_status', self::ACTIVE_STATUSES);
                        });
                });
            });
    }

    public function reverseIsActive(): bool
    {
        return filled($this->reverse_waybill)
            && (is_null($this->reverse_status) || in_array($this->reverse_status, self::ACTIVE_STATUSES, true));
    }

    public function scopeActiveForShiprocketSync(Builder $query): Builder
    {
        return $query->where('provider', self::PROVIDER_SHIPROCKET)
            ->whereNotNull('waybill')
            ->whereIn('shipment_status', self::ACTIVE_STATUSES);
    }

    public function hasWaybill(): bool
    {
        return filled($this->waybill);
    }

    public function trackingUrl(): ?string
    {
        if (!$this->waybill) {
            return null;
        }

        return $this->provider === self::PROVIDER_SHIPROCKET
            ? "https://shiprocket.co/tracking/{$this->waybill}"
            : "https://www.delhivery.com/track/package/{$this->waybill}";
    }
}
