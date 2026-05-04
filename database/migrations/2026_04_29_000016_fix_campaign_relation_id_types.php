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
        if (Schema::hasTable('campaigns') && Schema::hasColumn('campaigns', 'user_id')) {
            DB::statement("
                ALTER TABLE campaigns
                ALTER COLUMN user_id TYPE BIGINT
                USING (
                    CASE
                        WHEN trim(user_id) ~ '^[0-9]+$' THEN user_id::bigint
                        ELSE NULL
                    END
                )
            ");
        }

        if (Schema::hasTable('campaign_reports') && Schema::hasColumn('campaign_reports', 'campaign_id')) {
            DB::statement("
                ALTER TABLE campaign_reports
                ALTER COLUMN campaign_id TYPE BIGINT
                USING (
                    CASE
                        WHEN trim(campaign_id) ~ '^[0-9]+$' THEN campaign_id::bigint
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
        if (Schema::hasTable('campaigns') && Schema::hasColumn('campaigns', 'user_id')) {
            DB::statement('ALTER TABLE campaigns ALTER COLUMN user_id TYPE TEXT USING user_id::text');
        }

        if (Schema::hasTable('campaign_reports') && Schema::hasColumn('campaign_reports', 'campaign_id')) {
            DB::statement('ALTER TABLE campaign_reports ALTER COLUMN campaign_id TYPE TEXT USING campaign_id::text');
        }
    }
};
