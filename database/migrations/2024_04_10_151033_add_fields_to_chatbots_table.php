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
        Schema::table('chatbots', function (Blueprint $table) {
            $table->string('sr_no');
            $table->string('reference_no');
        });
        Schema::table('chatbot_auths', function (Blueprint $table) {
            $table->string('sr_no');
            $table->string('reference_no');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            //
        });
    }
};
