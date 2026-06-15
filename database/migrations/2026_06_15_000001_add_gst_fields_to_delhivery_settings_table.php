<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delhivery_settings', function (Blueprint $table) {
            $table->string('seller_gst_tin', 32)->nullable()->after('pickup_location');
            $table->string('default_hsn_code', 32)->nullable()->after('seller_gst_tin');
        });
    }

    public function down(): void
    {
        Schema::table('delhivery_settings', function (Blueprint $table) {
            $table->dropColumn(['seller_gst_tin', 'default_hsn_code']);
        });
    }
};
