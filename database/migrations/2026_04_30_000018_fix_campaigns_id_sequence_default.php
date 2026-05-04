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
        if (!Schema::hasTable('campaigns') || !Schema::hasColumn('campaigns', 'id')) {
            return;
        }

        DB::statement('CREATE SEQUENCE IF NOT EXISTS campaigns_id_seq');
        DB::statement("SELECT setval('campaigns_id_seq', COALESCE((SELECT MAX(id) FROM campaigns), 0))");
        DB::statement("ALTER TABLE campaigns ALTER COLUMN id SET DEFAULT nextval('campaigns_id_seq')");
        DB::statement("ALTER SEQUENCE campaigns_id_seq OWNED BY campaigns.id");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('campaigns') || !Schema::hasColumn('campaigns', 'id')) {
            return;
        }

        DB::statement('ALTER TABLE campaigns ALTER COLUMN id DROP DEFAULT');
    }
};
