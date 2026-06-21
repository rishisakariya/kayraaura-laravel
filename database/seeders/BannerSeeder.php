<?php

namespace Database\Seeders;

use App\Models\Banner;
use Illuminate\Database\Seeder;

class BannerSeeder extends Seeder
{
    public function run(): void
    {
        Banner::query()->updateOrCreate(
            ['id' => 1],
            [
                'image1' => null,
                'image2' => null,
                'image3' => null,
                'image4' => null,
                'video' => null,
                'banner_title' => 'New Discover Sparkle With Style',
                'banner_description' => 'Whether casual or formal, find the perfect jewelry for every occasion with us.',
                'video_title' => 'New Arrival',
                'video_description' => 'Presented in timeless adornment, for those who seek both beauty and refined minimalism.',
                'sort_order' => 1,
            ]
        );
    }
}
