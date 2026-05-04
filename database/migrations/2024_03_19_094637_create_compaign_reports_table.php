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
        if (Schema::hasTable('campaign_reports')) {
            return;
        }

        Schema::create('campaign_reports', function (Blueprint $table) {
            $table->id();
            $table->string('campaign_id');
            $table->string('message_id')->nullable();
            $table->string('user_name')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('status')->nullable();
            $table->string('exp_time')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_reports');
    }
};
