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
        if (Schema::hasColumn('customer_reviews', 'customer_name')) {
            DB::table('customer_reviews')->truncate();

            Schema::table('customer_reviews', function (Blueprint $table) {
                $table->dropIndex(['customer_email']);
            });

            Schema::table('customer_reviews', function (Blueprint $table) {
                $table->foreignId('user_id')->after('id')->constrained()->cascadeOnDelete();
                $table->dropColumn(['customer_name', 'customer_email', 'customer_phone', 'title']);
                $table->text('review')->nullable()->change();
            });
        }

        Schema::table('customer_reviews', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('customer_reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
        });

        Schema::table('customer_reviews', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::table('customer_reviews', function (Blueprint $table) {
            $table->unique(['user_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_reviews', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'product_id']);
            $table->dropForeign(['product_id']);
        });

        Schema::table('customer_reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });

        if (Schema::hasColumn('customer_reviews', 'user_id')) {
            Schema::table('customer_reviews', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
                $table->string('customer_name');
                $table->string('customer_email');
                $table->string('customer_phone')->nullable();
                $table->string('title')->nullable();
                $table->text('review')->nullable(false)->change();
                $table->index('customer_email');
            });
        }
    }
};
