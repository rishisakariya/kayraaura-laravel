<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('order_shipments')->cascadeOnDelete();
            $table->string('direction', 16)->default('forward');
            $table->string('old_status')->nullable();
            $table->string('new_status');
            $table->string('raw_status')->nullable();
            $table->string('source', 32);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['shipment_id', 'created_at']);
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_status_histories');
    }
};
