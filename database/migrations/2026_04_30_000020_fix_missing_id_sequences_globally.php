<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $rows = DB::select("
            SELECT table_name
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND column_name = 'id'
              AND data_type IN ('integer', 'bigint')
              AND is_nullable = 'NO'
              AND column_default IS NULL
            ORDER BY table_name
        ");

        foreach ($rows as $row) {
            $table = $row->table_name;
            $sequence = "{$table}_id_seq";

            DB::statement("CREATE SEQUENCE IF NOT EXISTS {$sequence}");
            $maxId = DB::table($table)->max('id');

            if ($maxId === null) {
                DB::statement("SELECT setval('{$sequence}', 1, false)");
            } else {
                DB::statement("SELECT setval('{$sequence}', {$maxId}, true)");
            }

            DB::statement("ALTER TABLE {$table} ALTER COLUMN id SET DEFAULT nextval('{$sequence}')");
            DB::statement("ALTER SEQUENCE {$sequence} OWNED BY {$table}.id");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally no-op. This migration repairs broken primary key defaults.
    }
};
