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
        if (Schema::hasTable('groups') && Schema::hasColumn('groups', 'user_id')) {
            DB::statement("
                ALTER TABLE groups
                ALTER COLUMN user_id TYPE BIGINT
                USING (
                    CASE
                        WHEN trim(user_id) ~ '^[0-9]+$' THEN user_id::bigint
                        ELSE NULL
                    END
                )
            ");
        }

        if (Schema::hasTable('contacts') && Schema::hasColumn('contacts', 'group_id')) {
            DB::statement("
                ALTER TABLE contacts
                ALTER COLUMN group_id TYPE BIGINT
                USING (
                    CASE
                        WHEN trim(group_id) ~ '^[0-9]+$' THEN group_id::bigint
                        ELSE NULL
                    END
                )
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('groups') && Schema::hasColumn('groups', 'user_id')) {
            DB::statement('ALTER TABLE groups ALTER COLUMN user_id TYPE TEXT USING user_id::text');
        }

        if (Schema::hasTable('contacts') && Schema::hasColumn('contacts', 'group_id')) {
            DB::statement('ALTER TABLE contacts ALTER COLUMN group_id TYPE TEXT USING group_id::text');
        }
    }
};
