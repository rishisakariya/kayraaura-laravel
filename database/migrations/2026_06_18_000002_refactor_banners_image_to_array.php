<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->json('image_media')->nullable()->after('id');
        });

        foreach (DB::table('banners')->get() as $banner) {
            $media = array_values(array_filter([
                $banner->image ?? null,
                $banner->video ?? null,
            ]));

            DB::table('banners')->where('id', $banner->id)->update([
                'image_media' => json_encode($media),
            ]);
        }

        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn(['image', 'video', 'video_title', 'video_description']);
        });

        Schema::table('banners', function (Blueprint $table) {
            $table->renameColumn('image_media', 'image');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->renameColumn('image', 'image_media');
        });

        Schema::table('banners', function (Blueprint $table) {
            $table->string('image')->nullable()->after('id');
            $table->string('video')->nullable()->after('image');
            $table->string('video_title')->nullable()->after('banner_description');
            $table->text('video_description')->nullable()->after('video_title');
        });

        foreach (DB::table('banners')->get() as $banner) {
            $media = json_decode($banner->image_media ?? '[]', true) ?: [];

            DB::table('banners')->where('id', $banner->id)->update([
                'image' => $media[0] ?? '',
                'video' => $media[1] ?? null,
            ]);
        }

        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn('image_media');
        });
    }
};
