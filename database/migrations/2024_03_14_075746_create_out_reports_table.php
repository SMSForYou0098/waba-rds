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
        if (Schema::hasTable('out_reports')) {
            return;
        }

        Schema::create('out_reports', function (Blueprint $table) {
            $table->id();
            $table->string('object');
            $table->string('report_id');
            $table->string('messaging_product');
            $table->string('display_phone_number');
            $table->string('phone_number_id');
            $table->string('status_id');
            $table->string('status');
            $table->string('timestamp');
            $table->string('recipient_id');
            $table->string('conversation_id');
            $table->string('expiration_timestamp');
            $table->string('billable');
            $table->string('pricing_model');
            $table->string('category');
            $table->string('field');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('out_reports');
    }
};
