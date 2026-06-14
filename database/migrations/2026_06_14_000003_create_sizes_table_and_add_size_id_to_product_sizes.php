<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sizes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();

        DB::table('product_sizes')
            ->select('size_text')
            ->whereNotNull('size_text')
            ->distinct()
            ->orderBy('size_text')
            ->get()
            ->each(function ($productSize, int $index) use ($now) {
                DB::table('sizes')->insertOrIgnore([
                    'name' => $productSize->size_text,
                    'sort_order' => $index,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        Schema::table('product_sizes', function (Blueprint $table) {
            $table->foreignId('size_id')
                ->nullable()
                ->after('product_id')
                ->constrained('sizes')
                ->restrictOnDelete();
        });

        DB::table('product_sizes')
            ->join('sizes', 'product_sizes.size_text', '=', 'sizes.name')
            ->update(['product_sizes.size_id' => DB::raw('sizes.id')]);

        Schema::table('product_sizes', function (Blueprint $table) {
            $table->unique(['product_id', 'size_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_sizes', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'size_id']);
            $table->dropConstrainedForeignId('size_id');
        });

        Schema::dropIfExists('sizes');
    }
};
