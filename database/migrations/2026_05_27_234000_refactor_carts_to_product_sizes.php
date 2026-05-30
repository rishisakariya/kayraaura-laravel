<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('carts', 'product_id')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->dropForeign(['product_id']);
            });

            Schema::table('carts', function (Blueprint $table) {
                $table->dropUnique(['user_id', 'product_id']);
            });
        }

        Schema::table('carts', function (Blueprint $table) {
            if (!Schema::hasColumn('carts', 'product_size_id')) {
                $table->foreignId('product_size_id')->nullable()->after('product_id');
            }

            if (!Schema::hasColumn('carts', 'size_text')) {
                $table->string('size_text')->nullable()->after('product_size_id');
            }

            if (!Schema::hasColumn('carts', 'size_price')) {
                $table->decimal('size_price', 12, 2)->nullable()->after('size_text');
            }
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('product_size_id')->references('id')->on('product_sizes')->cascadeOnDelete();
            $table->unique(['user_id', 'product_size_id']);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('carts', 'product_size_id')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->dropForeign(['product_size_id']);
            });

            Schema::table('carts', function (Blueprint $table) {
                $table->dropUnique(['user_id', 'product_size_id']);
            });
        }

        if (Schema::hasColumn('carts', 'product_id')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->dropForeign(['product_id']);
            });
        }

        Schema::table('carts', function (Blueprint $table) {
            foreach (['product_size_id', 'size_text', 'size_price'] as $column) {
                if (Schema::hasColumn('carts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasColumn('carts', 'product_id')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->unique(['user_id', 'product_id']);
            });
        }
    }
};
