<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_settings', function (Blueprint $table) {
            $table->string('instagram_url')->nullable()->after('logo');
            $table->string('facebook_url')->nullable()->after('instagram_url');
            $table->string('youtube_url')->nullable()->after('facebook_url');
            $table->string('whatsapp_url')->nullable()->after('youtube_url');
            $table->string('linkedin_url')->nullable()->after('whatsapp_url');
        });
    }

    public function down(): void
    {
        Schema::table('web_settings', function (Blueprint $table) {
            $table->dropColumn([
                'instagram_url',
                'facebook_url',
                'youtube_url',
                'whatsapp_url',
                'linkedin_url',
            ]);
        });
    }
};
