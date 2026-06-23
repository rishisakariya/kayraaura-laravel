<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    public const RETURN_WINDOW_DAYS = 3;

    protected $fillable = [
        'user_id',
        'address_id',
        'order_number',
        'checkout_type',
        'status',
        'subtotal',
        'tax_amount',
        'shipping_amount',
        'cod_charge',
        'buy_two_get_one_discount_amount',
        'first_order_discount_amount',
        'online_payment_discount_amount',
        'scratch_coupon_code',
        'discount_percent',
        'discount_amount',
        'total_amount',
        'payment_method',
        'payment_status',
        'razorpay_order_id',
        'razorpay_payment_id',
        'razorpay_signature',
        'paid_at',
        'delivered_at',
        'payment_failed_at',
        'cod_verified_at',
        'shipping_address',
        'billing_address',
        'notes',
        'return_request',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'billing_address' => 'array',
        'return_request' => 'array',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'cod_charge' => 'decimal:2',
        'buy_two_get_one_discount_amount' => 'decimal:2',
        'first_order_discount_amount' => 'decimal:2',
        'online_payment_discount_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'discount_percent' => 'integer',
        'total_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'delivered_at' => 'datetime',
        'payment_failed_at' => 'datetime',
        'cod_verified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(UserAddress::class, 'address_id');
    }

    public function razorpayPaymentLogs(): HasMany
    {
        return $this->hasMany(RazorpayPaymentLog::class);
    }

    public function shipment(): HasOne
    {
        return $this->hasOne(OrderShipment::class);
    }

    public static function generateOrderNumber(): string
    {
        $prefix = 'ORD';
        $timestamp = now()->format('Ymd');
        $random = mt_rand(1000, 9999);

        return $prefix . $timestamp . $random;
    }

    public function canBeCancelled(): bool
    {
        return $this->cancellationBlockReason() === null;
    }

    public function cancellationBlockReason(): ?string
    {
        if (in_array($this->status, ['cancelled', 'delivered', 'return_requested', 'returned'], true)) {
            return match ($this->status) {
                'cancelled' => 'Order is already cancelled',
                'delivered' => 'Delivered orders cannot be cancelled',
                'return_requested' => 'Order with an active return request cannot be cancelled',
                'returned' => 'Returned orders cannot be cancelled',
                default => 'Order cannot be cancelled',
            };
        }

        if ($this->payment_method === 'cod' && !$this->cod_verified_at) {
            return 'Order cannot be cancelled until COD verification is completed';
        }

        if ($this->payment_method === 'online' && $this->payment_status !== 'paid') {
            return 'Online order cannot be cancelled until payment is completed';
        }

        $shipmentStatus = $this->relationLoaded('shipment')
            ? ($this->shipment?->shipment_status ?? OrderShipment::STATUS_NOT_CREATED)
            : ($this->shipment()->value('shipment_status') ?? OrderShipment::STATUS_NOT_CREATED);

        if (!in_array($shipmentStatus, OrderShipment::CANCELLABLE_STATUSES, true)) {
            return 'Order cannot be cancelled after shipment pickup or while in transit';
        }

        return null;
    }

    public function canBeReturned(): bool
    {
        if ($this->status !== 'delivered') {
            return false;
        }

        return $this->hasReturnableItems();
    }

    public function hasReturnableItems(): bool
    {
        if (!$this->relationLoaded('orderItems')) {
            return $this->orderItems()
                ->whereColumn('returned_quantity', '<', 'quantity')
                ->exists();
        }

        return $this->orderItems->contains(
            fn (OrderItem $item) => $item->returnableQuantity() > 0
        );
    }

    public function cancel(): void
    {
        if ($this->canBeCancelled()) {
            $this->status = 'cancelled';
            $this->save();
        }
    }

    public function markReturnRequested(array $returnRequest): void
    {
        if (!$this->canBeReturned()) {
            throw new DomainException('Only delivered orders can be returned');
        }

        $this->status = 'return_requested';
        $this->return_request = $returnRequest;
        $this->save();
    }
}
