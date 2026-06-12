<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('delhivery');
            $table->string('waybill')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('delhivery_order_id')->nullable();
            $table->string('shipment_status')->default('not_created')->index();
            $table->string('raw_status')->nullable();
            $table->string('status_location')->nullable();
            $table->text('status_instructions')->nullable();
            $table->string('pickup_location')->nullable();
            $table->string('payment_mode')->nullable();
            $table->decimal('cod_amount', 10, 2)->default(0);
            $table->string('courier_tracking_url')->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedInteger('length_cm')->nullable();
            $table->unsignedInteger('width_cm')->nullable();
            $table->unsignedInteger('height_cm')->nullable();
            $table->string('shipping_label_url')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('manifested_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('rto_at')->nullable();
            $table->text('failed_reason')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('tracking_payload')->nullable();
            $table->timestamps();

            $table->unique('order_id');
            $table->unique('waybill');
            $table->index(['provider', 'shipment_status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_shipments');
    }
};
