<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            if (!Schema::hasColumn('order_shipments', 'pickup_request_id')) {
                $table->string('pickup_request_id')->nullable()->after('manifested_at');
            }

            if (!Schema::hasColumn('order_shipments', 'pickup_requested_at')) {
                $table->timestamp('pickup_requested_at')->nullable()->after('manifested_at');
            }

            if (!Schema::hasColumn('order_shipments', 'pickup_request_payload')) {
                $table->json('pickup_request_payload')->nullable()->after('manifested_at');
            }

            if (!Schema::hasColumn('order_shipments', 'pickup_request_response')) {
                $table->json('pickup_request_response')->nullable()->after('manifested_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('order_shipments', 'pickup_request_id') ? 'pickup_request_id' : null,
                Schema::hasColumn('order_shipments', 'pickup_requested_at') ? 'pickup_requested_at' : null,
                Schema::hasColumn('order_shipments', 'pickup_request_payload') ? 'pickup_request_payload' : null,
                Schema::hasColumn('order_shipments', 'pickup_request_response') ? 'pickup_request_response' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn(array_values($columns));
            }
        });
    }
};
