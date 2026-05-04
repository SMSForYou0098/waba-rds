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
        if (Schema::hasTable('chatbots')) {
            return;
        }

        Schema::create('chatbots', function (Blueprint $table) {
            $table->id();
            // $table->string('type')->nullable();
            // $table->string('user_id')->nullable();
            // $table->string('keyword')->nullable();
            // $table->string('template')->nullable();
            // $table->string('custom_text')->nullable();
            // $table->string('reply_labels')->nullable();
            // $table->string('button_labels')->nullable();
            // $table->string('callback_url')->nullable();
            // $table->string('status')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chatbots');
    }
};
