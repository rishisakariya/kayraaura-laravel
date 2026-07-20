<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('buy_qty')->default(2)->after('buy_two_get_one_free_enabled');
            $table->unsignedTinyInteger('get_qty')->default(1)->after('buy_qty');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('buy_qty')->nullable()->after('buy_two_get_one_discount_amount');
            $table->unsignedTinyInteger('get_qty')->nullable()->after('buy_qty');
        });
    }

    public function down(): void
    {
        Schema::table('web_settings', function (Blueprint $table) {
            $table->dropColumn(['buy_qty', 'get_qty']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['buy_qty', 'get_qty']);
        });
    }
};
