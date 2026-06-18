<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customer_reviews', function (Blueprint $table) {
            $table->boolean('on_web_show')->default(false)->after('review');
            $table->index(['product_id', 'on_web_show']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_reviews', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'on_web_show']);
            $table->dropColumn('on_web_show');
        });
    }
};
