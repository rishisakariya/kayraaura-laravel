<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_size_id')->nullable()->after('product_id')->constrained('product_sizes')->nullOnDelete();
            $table->string('product_name')->nullable()->after('product_size_id');
            $table->string('product_slug')->nullable()->after('product_name');
            $table->string('size_text')->nullable()->after('product_slug');
            $table->decimal('size_price', 12, 2)->nullable()->after('size_text');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['product_size_id']);
            $table->dropColumn([
                'product_size_id',
                'product_name',
                'product_slug',
                'size_text',
                'size_price',
            ]);
        });
    }
};
