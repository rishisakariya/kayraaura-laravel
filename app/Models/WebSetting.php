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
        'footer_description',
        'offer_line1',
        'offer_line2',
        'offer_line3',
        'offer_line4',
        'mobile_number',
        'logo',
        'instagram_url',
        'facebook_url',
        'youtube_url',
        'whatsapp_url',
        'linkedin_url',
        'buy_two_get_one_free_enabled',
        'first_order_discount_amount',
        'online_payment_discount_percent',
        'shipping_amount',
        'cod_charge',
    ];

    protected $casts = [
        'buy_two_get_one_free_enabled' => 'boolean',
        'first_order_discount_amount' => 'decimal:2',
        'online_payment_discount_percent' => 'integer',
        'shipping_amount' => 'decimal:2',
        'cod_charge' => 'decimal:2',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'email' => 'info@kayraaura.com',
                'address' => 'Kayraaura',
                'footer_description' => null,
                'offer_line1' => null,
                'offer_line2' => null,
                'offer_line3' => null,
                'offer_line4' => null,
                'mobile_number' => '+919999999999',
                'logo' => null,
                'instagram_url' => null,
                'facebook_url' => null,
                'youtube_url' => null,
                'whatsapp_url' => null,
                'linkedin_url' => null,
                'buy_two_get_one_free_enabled' => false,
                'first_order_discount_amount' => 50,
                'online_payment_discount_percent' => 10,
                'shipping_amount' => 50,
                'cod_charge' => 50,
            ]
        );
    }

    public function getLogoUrlAttribute(): ?string
    {
        return PublicStorage::url($this->logo);
    }
}
