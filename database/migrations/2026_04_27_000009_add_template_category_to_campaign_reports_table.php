<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('campaign_reports', 'template_category')) {
                $table->string('template_category')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('campaign_reports', function (Blueprint $table) {
            //
        });
    }
};

