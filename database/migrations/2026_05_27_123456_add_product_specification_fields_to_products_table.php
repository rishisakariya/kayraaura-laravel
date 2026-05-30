<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('brand')->nullable()->after('short_description');
            $table->string('base_material')->nullable()->after('brand');
            $table->string('plating')->nullable()->after('base_material');
            $table->string('gemstone')->nullable()->after('plating');
            $table->string('design')->nullable()->after('gemstone');
            $table->string('occasion')->nullable()->after('design');

            // men | woman | both (nullable)
            $table->string('ideal_for')->nullable()->after('occasion');

            $table->text('package_contents')->nullable()->after('ideal_for');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'brand',
                'base_material',
                'plating',
                'gemstone',
                'design',
                'occasion',
                'ideal_for',
                'package_contents',
            ]);
        });
    }
};

