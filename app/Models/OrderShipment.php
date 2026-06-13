<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderShipment extends Model
{
    public const PROVIDER_DELHIVERY = 'delhivery';

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

    public function scopeActiveForSync(Builder $query): Builder
    {
        return $query->where('provider', self::PROVIDER_DELHIVERY)
            ->whereNotNull('waybill')
            ->whereIn('shipment_status', self::ACTIVE_STATUSES);
    }

    public function hasWaybill(): bool
    {
        return filled($this->waybill);
    }

    public function trackingUrl(): ?string
    {
        return $this->waybill ? "https://www.delhivery.com/track/package/{$this->waybill}" : null;
    }
}
