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
        if (!Schema::hasTable('campaign_reports') || !Schema::hasColumn('campaign_reports', 'id')) {
            return;
        }

        DB::statement('CREATE SEQUENCE IF NOT EXISTS campaign_reports_id_seq');
        DB::statement("SELECT setval('campaign_reports_id_seq', COALESCE((SELECT MAX(id) FROM campaign_reports), 0))");
        DB::statement("ALTER TABLE campaign_reports ALTER COLUMN id SET DEFAULT nextval('campaign_reports_id_seq')");
        DB::statement("ALTER SEQUENCE campaign_reports_id_seq OWNED BY campaign_reports.id");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('campaign_reports') || !Schema::hasColumn('campaign_reports', 'id')) {
            return;
        }

        DB::statement('ALTER TABLE campaign_reports ALTER COLUMN id DROP DEFAULT');
    }
};
