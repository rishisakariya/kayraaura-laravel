<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('scratch_coupon_code', 6)->nullable()->after('cod_charge');
            $table->unsignedTinyInteger('discount_percent')->nullable()->after('scratch_coupon_code');
            $table->decimal('discount_amount', 10, 2)->default(0)->after('discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['scratch_coupon_code', 'discount_percent', 'discount_amount']);
        });
    }
};
