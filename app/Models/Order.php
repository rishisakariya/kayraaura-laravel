<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'address_id',
        'order_number',
        'checkout_type',
        'status',
        'subtotal',
        'tax_amount',
        'shipping_amount',
        'total_amount',
        'payment_method',
        'payment_status',
        'razorpay_order_id',
        'razorpay_payment_id',
        'razorpay_signature',
        'paid_at',
        'payment_failed_at',
        'cod_verified_at',
        'shipping_address',
        'billing_address',
        'notes',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'billing_address' => 'array',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_at' => 'datetime',
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
        return in_array($this->status, ['pending', 'pending_admin_confirmation'], true);
    }

    public function canBeReturned(): bool
    {
        return $this->status === 'delivered';
    }

    public function cancel(): void
    {
        if ($this->canBeCancelled()) {
            $this->status = 'cancelled';
            $this->save();
        }
    }

    public function markReturned(): void
    {
        if ($this->canBeReturned()) {
            $this->status = 'returned';
            $this->save();
        }
    }
}
