<?php

namespace Database\Seeders;

use App\Models\WebSetting;
use Illuminate\Database\Seeder;

class WebSettingSeeder extends Seeder
{
    public function run(): void
    {
        WebSetting::query()->updateOrCreate(
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
                'buy_two_get_one_free_enabled' => false,
                'buy_qty' => 2,
                'get_qty' => 1,
                'first_order_discount_amount' => 50,
                'online_payment_discount_percent' => 10,
                'shipping_amount' => 50,
                'cod_charge' => 50,
            ]
        );
    }
}
