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
        if (!Schema::hasTable('support_agents') || !Schema::hasColumn('support_agents', 'user_id')) {
            return;
        }

        DB::statement('ALTER TABLE support_agents ALTER COLUMN user_id TYPE BIGINT USING user_id::bigint');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('support_agents') || !Schema::hasColumn('support_agents', 'user_id')) {
            return;
        }

        DB::statement('ALTER TABLE support_agents ALTER COLUMN user_id TYPE TEXT USING user_id::text');
    }
};
