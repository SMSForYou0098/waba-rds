<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chatbot_memories')) {
            return;
        }

        Schema::create('chatbot_memories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('key');
            $table->longText('value')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'mobile_number']);
            $table->index('key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_memories');
    }
};

