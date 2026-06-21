<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->string('image1')->nullable()->after('id');
            $table->string('image2')->nullable()->after('image1');
            $table->string('image3')->nullable()->after('image2');
            $table->string('image4')->nullable()->after('image3');
        });

        foreach (DB::table('banners')->get() as $banner) {
            $images = json_decode($banner->image ?? '[]', true) ?: [];

            DB::table('banners')->where('id', $banner->id)->update([
                'image1' => $images[0] ?? null,
                'image2' => $images[1] ?? null,
                'image3' => $images[2] ?? null,
                'image4' => $images[3] ?? null,
            ]);
        }

        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->json('image')->nullable()->after('id');
        });

        foreach (DB::table('banners')->get() as $banner) {
            DB::table('banners')->where('id', $banner->id)->update([
                'image' => json_encode(array_values(array_filter([
                    $banner->image1,
                    $banner->image2,
                    $banner->image3,
                    $banner->image4,
                ]))),
            ]);
        }

        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn(['image1', 'image2', 'image3', 'image4']);
        });
    }
};
