<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RazorpayPaymentLog extends Model
{
    protected $fillable = [
        'order_id',
        'user_id',
        'razorpay_order_id',
        'razorpay_payment_id',
        'event_type',
        'status',
        'request_payload',
        'response_payload',
        'webhook_payload',
        'webhook_signature',
        'signature_verified',
        'error_code',
        'error_description',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'webhook_payload' => 'array',
        'signature_verified' => 'boolean',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
