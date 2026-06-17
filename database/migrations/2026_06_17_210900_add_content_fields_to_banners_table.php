<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->string('banner_title')->nullable()->after('image');
            $table->text('banner_description')->nullable()->after('banner_title');
            $table->string('video_title')->nullable()->after('banner_description');
            $table->text('video_description')->nullable()->after('video_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn([
                'banner_title',
                'banner_description',
                'video_title',
                'video_description',
            ]);
        });
    }
};
