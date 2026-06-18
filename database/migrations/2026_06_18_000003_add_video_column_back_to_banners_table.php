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
            $table->string('video')->nullable()->after('image');
        });

        foreach (DB::table('banners')->get() as $banner) {
            $media = json_decode($banner->image ?? '[]', true) ?: [];
            $images = [];
            $video = null;

            foreach ($media as $item) {
                if ($this->isVideoPath($item)) {
                    $video ??= $item;
                    continue;
                }

                $images[] = $item;
            }

            DB::table('banners')->where('id', $banner->id)->update([
                'image' => json_encode(array_values($images)),
                'video' => $video,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (DB::table('banners')->get() as $banner) {
            $images = json_decode($banner->image ?? '[]', true) ?: [];

            if ($banner->video) {
                $images[] = $banner->video;
            }

            DB::table('banners')->where('id', $banner->id)->update([
                'image' => json_encode(array_values($images)),
            ]);
        }

        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn('video');
        });
    }

    private function isVideoPath(string $path): bool
    {
        $extension = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION));

        return in_array($extension, ['mp4', 'mov', 'avi', 'webm'], true);
    }
};
