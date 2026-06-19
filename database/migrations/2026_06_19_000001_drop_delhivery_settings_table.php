<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('delhivery_settings');
    }

    public function down(): void
    {
        Schema::create('delhivery_settings', function (Blueprint $table) {
            $table->id();
            $table->string('client_name');
            $table->string('pickup_location');
            $table->string('seller_gst_tin', 32)->nullable();
            $table->string('default_hsn_code', 32)->nullable();
            $table->unsignedInteger('default_length_cm')->default(10);
            $table->unsignedInteger('default_width_cm')->default(10);
            $table->unsignedInteger('default_height_cm')->default(5);
            $table->timestamps();
        });
    }
};
