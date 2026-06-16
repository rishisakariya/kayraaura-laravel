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
                'mobile_number' => '+919999999999',
                'logo' => null,
                'buy_two_get_one_free_enabled' => false,
            ]
        );
    }
}
