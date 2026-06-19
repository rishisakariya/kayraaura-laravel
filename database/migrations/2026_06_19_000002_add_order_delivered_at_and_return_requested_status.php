<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('paid_at');
        });

        DB::statement("ALTER TABLE orders MODIFY status ENUM('pending', 'pending_admin_confirmation', 'processing', 'shipped', 'delivered', 'return_requested', 'returned', 'cancelled', 'manual_review') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("UPDATE orders SET status = 'delivered' WHERE status = 'return_requested'");

        DB::statement("ALTER TABLE orders MODIFY status ENUM('pending', 'pending_admin_confirmation', 'processing', 'shipped', 'delivered', 'returned', 'cancelled', 'manual_review') NOT NULL DEFAULT 'pending'");

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivered_at');
        });
    }
};
