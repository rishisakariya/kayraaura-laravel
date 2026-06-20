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
        if (! $this->indexExists('customer_reviews_user_id_product_id_unique')) {
            return;
        }

        $this->dropForeignIfExists('customer_reviews_user_id_foreign');
        $this->dropForeignIfExists('customer_reviews_product_id_foreign');

        Schema::table('customer_reviews', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'product_id']);
        });

        if (! $this->foreignKeyExists('customer_reviews_user_id_foreign')) {
            Schema::table('customer_reviews', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! $this->foreignKeyExists('customer_reviews_product_id_foreign')) {
            Schema::table('customer_reviews', function (Blueprint $table) {
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: handled by earlier migrations.
    }

    private function indexExists(string $indexName): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'customer_reviews')
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }

    private function foreignKeyExists(string $constraintName): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'customer_reviews')
            ->where('CONSTRAINT_NAME', $constraintName)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    private function dropForeignIfExists(string $constraintName): void
    {
        if (! $this->foreignKeyExists($constraintName)) {
            return;
        }

        DB::statement("ALTER TABLE `customer_reviews` DROP FOREIGN KEY `{$constraintName}`");
    }
};
