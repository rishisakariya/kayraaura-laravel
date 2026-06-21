<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_settings', function (Blueprint $table) {
            $table->decimal('first_order_discount_amount', 10, 2)->default(50)->after('buy_two_get_one_free_enabled');
            $table->unsignedTinyInteger('online_payment_discount_percent')->default(10)->after('first_order_discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('web_settings', function (Blueprint $table) {
            $table->dropColumn([
                'first_order_discount_amount',
                'online_payment_discount_percent',
            ]);
        });
    }
};
