<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            $table->boolean('is_downloaded')->default(false)->after('shipping_label_url');
        });
    }

    public function down(): void
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            $table->dropColumn('is_downloaded');
        });
    }
};
