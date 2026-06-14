<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the newest user for each duplicated phone and clear older duplicates
        // before creating the unique index.
        DB::statement("
            UPDATE users u
            JOIN (
                SELECT phone, MAX(id) AS keep_id
                FROM users
                WHERE phone IS NOT NULL AND phone != ''
                GROUP BY phone
                HAVING COUNT(*) > 1
            ) dupes ON u.phone = dupes.phone AND u.id != dupes.keep_id
            SET u.phone = NULL
        ");

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_phone_unique');
            $table->unique('email');
        });
    }
};
