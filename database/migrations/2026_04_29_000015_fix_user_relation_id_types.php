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
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'reporting_user')) {
            DB::statement("
                ALTER TABLE users
                ALTER COLUMN reporting_user TYPE BIGINT
                USING (
                    CASE
                        WHEN trim(reporting_user) ~ '^[0-9]+$' THEN reporting_user::bigint
                        ELSE NULL
                    END
                )
            ");
        }

        if (Schema::hasTable('chatbots') && Schema::hasColumn('chatbots', 'user_id')) {
            DB::statement("
                ALTER TABLE chatbots
                ALTER COLUMN user_id TYPE BIGINT
                USING (
                    CASE
                        WHEN trim(user_id) ~ '^[0-9]+$' THEN user_id::bigint
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
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'reporting_user')) {
            DB::statement('ALTER TABLE users ALTER COLUMN reporting_user TYPE TEXT USING reporting_user::text');
        }

        if (Schema::hasTable('chatbots') && Schema::hasColumn('chatbots', 'user_id')) {
            DB::statement('ALTER TABLE chatbots ALTER COLUMN user_id TYPE TEXT USING user_id::text');
        }
    }
};
