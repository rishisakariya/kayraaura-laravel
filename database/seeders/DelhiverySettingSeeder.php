<?php

namespace Database\Seeders;

use App\Models\DelhiverySetting;
use Illuminate\Database\Seeder;

class DelhiverySettingSeeder extends Seeder
{
    public function run(): void
    {
        DelhiverySetting::query()->updateOrCreate(
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
