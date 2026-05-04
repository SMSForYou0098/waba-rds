<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('out_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('out_reports', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable();
                $table->index('user_id');
            }

            if (!Schema::hasColumn('out_reports', 'campaign_id')) {
                $table->unsignedBigInteger('campaign_id')->nullable();
                $table->index('campaign_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('out_reports', function (Blueprint $table) {
            //
        });
    }
};

