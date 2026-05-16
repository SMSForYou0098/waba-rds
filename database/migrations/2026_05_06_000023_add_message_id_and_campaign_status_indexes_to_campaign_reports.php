<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaign_reports')) {
            return;
        }

        try {
            Schema::table('campaign_reports', function (Blueprint $table): void {
                $table->index('message_id', 'campaign_reports_message_id_idx');
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('campaign_reports', function (Blueprint $table): void {
                $table->index(['campaign_id', 'status'], 'campaign_reports_campaign_status_idx');
            });
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        Schema::table('campaign_reports', function (Blueprint $table): void {
            try {
                $table->dropIndex('campaign_reports_campaign_status_idx');
            } catch (\Throwable) {
            }
            try {
                $table->dropIndex('campaign_reports_message_id_idx');
            } catch (\Throwable) {
            }
        });
    }
};
