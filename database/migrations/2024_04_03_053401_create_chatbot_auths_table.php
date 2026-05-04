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
        if (Schema::hasTable('chatbot_auths')) {
            return;
        }

        Schema::create('chatbot_auths', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->bigInteger('chatbot_id')->nullable();
            $table->bigInteger('template_name')->nullable();
            $table->string('keyword')->nullable();
            $table->string('custom_message')->nullable();
            $table->string('url')->nullable();
            $table->string('url_res_type')->nullable();
            $table->string('send_res_type')->nullable();
            $table->string('res')->nullable();
            $table->string('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chatbot_auths');
    }
};
