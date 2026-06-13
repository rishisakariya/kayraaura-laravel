<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            $table->string('reverse_waybill')->nullable()->unique()->after('waybill');
            $table->string('reverse_provider_reference')->nullable()->after('reverse_waybill');
            $table->string('reverse_status')->nullable()->index()->after('reverse_provider_reference');
            $table->string('reverse_tracking_url')->nullable()->after('reverse_status');
            $table->timestamp('reverse_requested_at')->nullable()->after('reverse_tracking_url');
            $table->text('reverse_failed_reason')->nullable()->after('reverse_requested_at');
            $table->json('reverse_request_payload')->nullable()->after('reverse_failed_reason');
            $table->json('reverse_response_payload')->nullable()->after('reverse_request_payload');
        });
    }

    public function down(): void
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            $table->dropUnique(['reverse_waybill']);
            $table->dropIndex(['reverse_status']);
            $table->dropColumn([
                'reverse_waybill',
                'reverse_provider_reference',
                'reverse_status',
                'reverse_tracking_url',
                'reverse_requested_at',
                'reverse_failed_reason',
                'reverse_request_payload',
                'reverse_response_payload',
            ]);
        });
    }
};
