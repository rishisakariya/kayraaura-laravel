<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('order_shipments', 'estimated_return_at')) {
            return;
        }

        Schema::table('order_shipments', function (Blueprint $table) {
            $table->timestamp('estimated_return_at')->nullable()->after('reverse_requested_at');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('order_shipments', 'estimated_return_at')) {
            return;
        }

        Schema::table('order_shipments', function (Blueprint $table) {
            $table->dropColumn('estimated_return_at');
        });
    }
};
