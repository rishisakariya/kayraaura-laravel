<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('carts', 'product_id')) {
            $this->dropForeignIfExists('carts', 'carts_product_id_foreign');
            $this->dropForeignIfExists('carts', 'carts_user_id_foreign');
            $this->dropIndexIfExists('carts', 'carts_user_id_product_id_unique');
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

        $this->addIndexIfMissing('carts', 'carts_product_id_index', ['product_id']);
        $this->addIndexIfMissing('carts', 'carts_product_size_id_index', ['product_size_id']);
        $this->addUniqueIfMissing('carts', 'carts_user_id_product_size_id_unique', ['user_id', 'product_size_id']);

        Schema::table('carts', function (Blueprint $table) {
            if (!$this->foreignKeyExists('carts', 'carts_user_id_foreign')) {
                $table->foreign('user_id', 'carts_user_id_foreign')->references('id')->on('users')->cascadeOnDelete();
            }

            if (!$this->foreignKeyExists('carts', 'carts_product_id_foreign')) {
                $table->foreign('product_id', 'carts_product_id_foreign')->references('id')->on('products')->cascadeOnDelete();
            }

            if (!$this->foreignKeyExists('carts', 'carts_product_size_id_foreign')) {
                $table->foreign('product_size_id', 'carts_product_size_id_foreign')->references('id')->on('product_sizes')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('carts', 'product_size_id')) {
            $this->dropForeignIfExists('carts', 'carts_product_size_id_foreign');
            $this->dropForeignIfExists('carts', 'carts_product_id_foreign');
            $this->dropForeignIfExists('carts', 'carts_user_id_foreign');
            $this->dropIndexIfExists('carts', 'carts_user_id_product_size_id_unique');
            $this->dropIndexIfExists('carts', 'carts_product_size_id_index');
        }

        if (Schema::hasColumn('carts', 'product_id')) {
            $this->dropIndexIfExists('carts', 'carts_product_id_index');
        }

        Schema::table('carts', function (Blueprint $table) {
            foreach (['product_size_id', 'size_text', 'size_price'] as $column) {
                if (Schema::hasColumn('carts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasColumn('carts', 'product_id')) {
            $this->addUniqueIfMissing('carts', 'carts_user_id_product_id_unique', ['user_id', 'product_id']);

            Schema::table('carts', function (Blueprint $table) {
                if (!$this->foreignKeyExists('carts', 'carts_user_id_foreign')) {
                    $table->foreign('user_id', 'carts_user_id_foreign')->references('id')->on('users')->cascadeOnDelete();
                }

                if (!$this->foreignKeyExists('carts', 'carts_product_id_foreign')) {
                    $table->foreign('product_id', 'carts_product_id_foreign')->references('id')->on('products')->cascadeOnDelete();
                }
            });
        }
    }

    private function addIndexIfMissing(string $tableName, string $indexName, array $columns): void
    {
        if ($this->indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
            $table->index($columns, $indexName);
        });
    }

    private function addUniqueIfMissing(string $tableName, string $indexName, array $columns): void
    {
        if ($this->indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
            $table->unique($columns, $indexName);
        });
    }

    private function dropForeignIfExists(string $tableName, string $constraintName): void
    {
        if (!$this->foreignKeyExists($tableName, $constraintName)) {
            return;
        }

        DB::statement("ALTER TABLE `{$tableName}` DROP FOREIGN KEY `{$constraintName}`");
    }

    private function dropIndexIfExists(string $tableName, string $indexName): void
    {
        if (!$this->indexExists($tableName, $indexName)) {
            return;
        }

        DB::statement("ALTER TABLE `{$tableName}` DROP INDEX `{$indexName}`");
    }

    private function foreignKeyExists(string $tableName, string $constraintName): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $tableName)
            ->where('CONSTRAINT_NAME', $constraintName)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $tableName)
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }
};
