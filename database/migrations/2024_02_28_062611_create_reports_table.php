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
        if (Schema::hasTable('reports')) {
            return;
        }

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('object');
            $table->string('report_id');
            $table->string('messaging_product');
            $table->string('display_phone_number');
            $table->string('phone_number_id');
            $table->string('profile_name');
            $table->string('wa_id');
            $table->string('messages_id');
            $table->string('messages_type');
            $table->string('timestamp');
            $table->string('text_body');
            $table->string('field');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
