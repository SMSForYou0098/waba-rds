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

        Schema::table('campaign_reports', function (Blueprint $table): void {
            if (! Schema::hasColumn('campaign_reports', 'whatsapp_phone_id')) {
                $table->string('whatsapp_phone_id')->nullable()->after('mobile_number');
            }
            if (! Schema::hasColumn('campaign_reports', 'payload')) {
                $table->json('payload')->nullable()->after('whatsapp_phone_id');
            }
            if (! Schema::hasColumn('campaign_reports', 'attempts')) {
                $table->unsignedSmallInteger('attempts')->default(0)->after('status');
            }
            if (! Schema::hasColumn('campaign_reports', 'last_error_code')) {
                $table->string('last_error_code')->nullable()->after('attempts');
            }
            if (! Schema::hasColumn('campaign_reports', 'last_error')) {
                $table->text('last_error')->nullable()->after('last_error_code');
            }
            if (! Schema::hasColumn('campaign_reports', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('message_id');
            }
        });

        try {
            Schema::table('campaign_reports', function (Blueprint $table): void {
                $table->index('status', 'campaign_reports_status_idx');
            });
        } catch (Throwable) {
        }
        try {
            Schema::table('campaign_reports', function (Blueprint $table): void {
                $table->index(['status', 'whatsapp_phone_id'], 'campaign_reports_status_phone_idx');
            });
        } catch (Throwable) {
        }
        try {
            Schema::table('campaign_reports', function (Blueprint $table): void {
                $table->unique(['campaign_id', 'mobile_number'], 'campaign_reports_campaign_mobile_unique');
            });
        } catch (Throwable) {
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('campaign_reports')) {
            return;
        }

        Schema::table('campaign_reports', function (Blueprint $table): void {
            try {
                $table->dropUnique('campaign_reports_campaign_mobile_unique');
            } catch (\Throwable) {
            }
            try {
                $table->dropIndex('campaign_reports_status_phone_idx');
            } catch (\Throwable) {
            }
            try {
                $table->dropIndex('campaign_reports_status_idx');
            } catch (\Throwable) {
            }
        });

        Schema::table('campaign_reports', function (Blueprint $table): void {
            if (Schema::hasColumn('campaign_reports', 'sent_at')) {
                $table->dropColumn('sent_at');
            }
            if (Schema::hasColumn('campaign_reports', 'last_error')) {
                $table->dropColumn('last_error');
            }
            if (Schema::hasColumn('campaign_reports', 'last_error_code')) {
                $table->dropColumn('last_error_code');
            }
            if (Schema::hasColumn('campaign_reports', 'attempts')) {
                $table->dropColumn('attempts');
            }
            if (Schema::hasColumn('campaign_reports', 'payload')) {
                $table->dropColumn('payload');
            }
            if (Schema::hasColumn('campaign_reports', 'whatsapp_phone_id')) {
                $table->dropColumn('whatsapp_phone_id');
            }
        });
    }
};
