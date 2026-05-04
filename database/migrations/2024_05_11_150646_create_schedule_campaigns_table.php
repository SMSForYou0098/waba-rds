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
        if (Schema::hasTable('schedule_campaigns')) {
            return;
        }

        Schema::create('schedule_campaigns', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('name')->nullable();
            $table->string('numbers')->nullable();
            $table->string('campaign_type')->nullable();
            $table->string('custom_text')->nullable();
            $table->string('template_name')->nullable();
            $table->string('header_type')->nullable();
            $table->string('header_media_url')->nullable();
            $table->string('body_values')->nullable();
            $table->string('button_type')->nullable();
            $table->string('button_value')->nullable();
            $table->string('template_body_object')->nullable();
            $table->date('schedule_date')->nullable();
            $table->time('schedule_time')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_campaigns');
    }
};
