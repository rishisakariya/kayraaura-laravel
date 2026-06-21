<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'address' => $this->address,
            'footer_description' => $this->footer_description,
            'mobile_number' => $this->mobile_number,
            'logo' => $this->logo,
            'logo_url' => $this->logo_url,
            'instagram_url' => $this->instagram_url,
            'facebook_url' => $this->facebook_url,
            'youtube_url' => $this->youtube_url,
            'whatsapp_url' => $this->whatsapp_url,
            'linkedin_url' => $this->linkedin_url,
            'buy_two_get_one_free_enabled' => (bool) $this->buy_two_get_one_free_enabled,
            'first_order_discount_amount' => (float) ($this->first_order_discount_amount ?? 0),
            'online_payment_discount_percent' => (int) ($this->online_payment_discount_percent ?? 0),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
