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
        $targets = [
            'badges' => ['user_id'],
            'balances' => ['account_manager_id', 'report_id'],
            'chat_histories' => ['out_report_id', 'report_id', 'user_id'],
            'chatbot_ideal_timers' => ['user_id'],
            'chatbot_memories' => ['user_id'],
            'chatbot_states' => ['user_id'],
            'crousel_presets' => ['user_id'],
            'email_templates' => ['user_id'],
            'idle_message_users' => ['user_id'],
            'list_message_presets' => ['user_id'],
            'media' => ['user_id'],
            'out_reports' => ['report_id', 'user_id'],
            'plan_configs' => ['plan_id'],
            'report_has_badges' => ['badge_id', 'report_id', 'user_id'],
            'schedule_campaign_reports' => ['campaign_id'],
            'service_messages' => ['user_id'],
        ];

        foreach ($targets as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::statement("
                    ALTER TABLE {$table}
                    ALTER COLUMN {$column} TYPE BIGINT
                    USING (
                        CASE
                            WHEN {$column} IS NULL THEN NULL
                            WHEN trim({$column}::text) ~ '^[0-9]+$' THEN trim({$column}::text)::bigint
                            ELSE NULL
                        END
                    )
                ");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op on purpose; this is a one-way data normalization for Postgres compatibility.
    }
};
