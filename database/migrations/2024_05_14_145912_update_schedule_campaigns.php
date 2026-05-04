<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('schedule_campaigns')) {
            return;
        }

        Schema::table('schedule_campaigns', function (Blueprint $table) {
            if (!Schema::hasColumn('schedule_campaigns', 'status')) {
                $table->string('status')->nullable();
            }
            if (!Schema::hasColumn('schedule_campaigns', 'ip')) {
                $table->string('ip')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedule_campaigns', function (Blueprint $table) {
            //
        });
    }
};
