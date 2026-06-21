<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\PublicStorage;

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
        'instagram_url',
        'facebook_url',
        'youtube_url',
        'whatsapp_url',
        'linkedin_url',
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
                'instagram_url' => null,
                'facebook_url' => null,
                'youtube_url' => null,
                'whatsapp_url' => null,
                'linkedin_url' => null,
                'buy_two_get_one_free_enabled' => false,
            ]
        );
    }

    public function getLogoUrlAttribute(): ?string
    {
        return PublicStorage::url($this->logo);
    }
}
