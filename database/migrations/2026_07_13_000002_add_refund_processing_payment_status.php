<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY payment_status ENUM('pending', 'paid', 'failed', 'refunded', 'paid_stock_failed', 'refund_processing') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY payment_status ENUM('pending', 'paid', 'failed', 'refunded', 'paid_stock_failed') NOT NULL DEFAULT 'pending'");
        }
    }
};
