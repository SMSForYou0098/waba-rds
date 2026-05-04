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
        if (Schema::hasTable('model_has_roles')) {
            DB::statement("UPDATE model_has_roles SET model_type = REPLACE(model_type, repeat(chr(92),2), chr(92)) WHERE position(repeat(chr(92),2) in model_type) > 0");
        }

        if (Schema::hasTable('model_has_permissions')) {
            DB::statement("UPDATE model_has_permissions SET model_type = REPLACE(model_type, repeat(chr(92),2), chr(92)) WHERE position(repeat(chr(92),2) in model_type) > 0");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: this migration fixes malformed class names in existing data.
    }
};
