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
