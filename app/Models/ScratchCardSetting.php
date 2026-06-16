<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScratchCardSetting extends Model
{
    protected $fillable = [
        'min_discount_percent',
        'max_discount_percent',
        'is_active',
    ];

    protected $casts = [
        'min_discount_percent' => 'integer',
        'max_discount_percent' => 'integer',
        'is_active' => 'boolean',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'min_discount_percent' => 1,
                'max_discount_percent' => 50,
                'is_active' => false,
            ]
        );
    }
}
