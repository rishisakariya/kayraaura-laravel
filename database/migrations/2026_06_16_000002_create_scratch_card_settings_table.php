<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scratch_card_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('min_discount_percent')->default(1);
            $table->unsignedTinyInteger('max_discount_percent')->default(50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scratch_card_settings');
    }
};
