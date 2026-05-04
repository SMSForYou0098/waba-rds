<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('userconfigs')) {
            return;
        }

        DB::statement('ALTER TABLE userconfigs ALTER COLUMN user_id TYPE BIGINT USING user_id::bigint');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('userconfigs')) {
            return;
        }

        DB::statement('ALTER TABLE userconfigs ALTER COLUMN user_id TYPE TEXT USING user_id::text');
    }
};
