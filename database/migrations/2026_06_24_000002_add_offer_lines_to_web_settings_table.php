<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_settings', function (Blueprint $table) {
            $table->string('offer_line1')->nullable()->after('footer_description');
            $table->string('offer_line2')->nullable()->after('offer_line1');
            $table->string('offer_line3')->nullable()->after('offer_line2');
            $table->string('offer_line4')->nullable()->after('offer_line3');
        });
    }

    public function down(): void
    {
        Schema::table('web_settings', function (Blueprint $table) {
            $table->dropColumn(['offer_line1', 'offer_line2', 'offer_line3', 'offer_line4']);
        });
    }
};
