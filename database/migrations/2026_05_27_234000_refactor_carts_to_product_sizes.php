<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            // In some MySQL setups the unique index cannot be dropped because it's needed by FK constraints.
            // Wrap in try/catch so migration can continue.
            if (Schema::hasColumn('carts', 'product_id')) {
                try {
                    $table->dropUnique(['user_id', 'product_id']);
                } catch (\Throwable $e) {
                    // no-op
                }
            }

            // Add size-based columns
            if (!Schema::hasColumn('carts', 'product_size_id')) {
                $table->foreignId('product_size_id')->nullable()->after('product_id');
            }

            if (!Schema::hasColumn('carts', 'size_text')) {
                $table->string('size_text')->nullable()->after('product_size_id');
            }

            if (!Schema::hasColumn('carts', 'size_price')) {
                $table->decimal('size_price', 12, 2)->nullable()->after('size_text');
            }

            // New uniqueness per size
            $table->unique(['user_id', 'product_size_id']);
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            // Drop new unique
            try {
                $table->dropUnique(['user_id', 'product_size_id']);
            } catch (\Throwable $e) {
                // no-op
            }

            // Drop new columns
            foreach (['product_size_id', 'size_text', 'size_price'] as $column) {
                if (Schema::hasColumn('carts', $column)) {
                    $table->dropColumn($column);
                }
            }

            // Restore old unique
            if (Schema::hasColumn('carts', 'product_id')) {
                $table->unique(['user_id', 'product_id']);
            }
        });
    }
};

