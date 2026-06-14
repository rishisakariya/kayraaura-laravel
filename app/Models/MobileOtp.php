<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileOtp extends Model
{
    protected $fillable = [
        'mobile',
        'purpose',
        'otp_hash',
        'attempts',
        'last_sent_at',
        'expires_at',
        'verified_at',
        'consumed_at',
    ];

    protected $hidden = [
        'otp_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
