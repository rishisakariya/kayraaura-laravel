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
                'client_name' => 'RS',
                'pickup_location' => 'RSNEW',
                'default_length_cm' => 10,
                'default_width_cm' => 10,
                'default_height_cm' => 5,
            ]
        );
    }
}
