<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class WebSetting extends Model
{
    protected $appends = [
        'logo_url',
    ];

    protected $fillable = [
        'email',
        'address',
        'mobile_number',
        'logo',
        'buy_two_get_one_free_enabled',
    ];

    protected $casts = [
        'buy_two_get_one_free_enabled' => 'boolean',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'email' => 'info@kayraaura.com',
                'address' => 'Kayraaura',
                'mobile_number' => '+919999999999',
                'logo' => null,
                'buy_two_get_one_free_enabled' => false,
            ]
        );
    }

    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo) {
            return null;
        }

        return filter_var($this->logo, FILTER_VALIDATE_URL)
            ? $this->logo
            : Storage::disk('public')->url($this->logo);
    }
}
