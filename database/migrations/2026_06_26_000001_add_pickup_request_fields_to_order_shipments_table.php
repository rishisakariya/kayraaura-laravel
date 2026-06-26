<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            $table->string('pickup_request_id')->nullable()->after('manifested_at');
            $table->timestamp('pickup_requested_at')->nullable()->after('pickup_request_id');
            $table->json('pickup_request_payload')->nullable()->after('pickup_requested_at');
            $table->json('pickup_request_response')->nullable()->after('pickup_request_payload');
        });
    }

    public function down(): void
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            $table->dropColumn([
                'pickup_request_id',
                'pickup_requested_at',
                'pickup_request_payload',
                'pickup_request_response',
            ]);
        });
    }
};
