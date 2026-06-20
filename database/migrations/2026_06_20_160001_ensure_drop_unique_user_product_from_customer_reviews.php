<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if ($this->indexExists('customer_reviews_user_id_product_id_unique')) {
            Schema::table('customer_reviews', function (Blueprint $table) {
                $table->dropUnique(['user_id', 'product_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! $this->indexExists('customer_reviews_user_id_product_id_unique')) {
            Schema::table('customer_reviews', function (Blueprint $table) {
                $table->unique(['user_id', 'product_id']);
            });
        }
    }

    private function indexExists(string $indexName): bool
    {
        return ! empty(DB::select(
            "
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
                AND table_name = 'customer_reviews'
                AND index_name = ?
            LIMIT 1
            ",
            [$indexName]
        ));
    }
};
