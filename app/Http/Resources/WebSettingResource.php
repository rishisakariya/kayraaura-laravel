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
            'offer_line1' => $this->offer_line1,
            'offer_line2' => $this->offer_line2,
            'offer_line3' => $this->offer_line3,
            'offer_line4' => $this->offer_line4,
            'mobile_number' => $this->mobile_number,
            'logo' => $this->logo,
            'logo_url' => $this->logo_url,
            'instagram_url' => $this->instagram_url,
            'facebook_url' => $this->facebook_url,
            'youtube_url' => $this->youtube_url,
            'whatsapp_url' => $this->whatsapp_url,
            'linkedin_url' => $this->linkedin_url,
            'buy_two_get_one_free_enabled' => (bool) $this->buy_two_get_one_free_enabled,
            'buy_qty' => (int) ($this->buy_qty ?? 2),
            'get_qty' => (int) ($this->get_qty ?? 1),
            'first_order_discount_amount' => (float) ($this->first_order_discount_amount ?? 0),

            'online_payment_discount_percent' => (int) ($this->online_payment_discount_percent ?? 0),
            'shipping_amount' => (float) ($this->shipping_amount ?? 0),
            'cod_charge' => (float) ($this->cod_charge ?? 0),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
