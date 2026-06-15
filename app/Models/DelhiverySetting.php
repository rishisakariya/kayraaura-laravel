<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DelhiverySetting extends Model
{
    protected $fillable = [
        'client_name',
        'pickup_location',
        'seller_gst_tin',
        'default_hsn_code',
        'default_length_cm',
        'default_width_cm',
        'default_height_cm',
    ];

    protected $casts = [
        'default_length_cm' => 'integer',
        'default_width_cm' => 'integer',
        'default_height_cm' => 'integer',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'client_name' => config('delhivery.client_name') ?: 'RS',
                'pickup_location' => config('delhivery.pickup_location') ?: 'RSNEW',
                'seller_gst_tin' => config('delhivery.seller_gst_tin'),
                'default_hsn_code' => config('delhivery.default_hsn_code'),
                'default_length_cm' => 10,
                'default_width_cm' => 10,
                'default_height_cm' => 5,
            ]
        );
    }
}
