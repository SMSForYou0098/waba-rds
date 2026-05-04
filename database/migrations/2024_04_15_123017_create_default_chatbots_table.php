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
        if (Schema::hasTable('default_chatbots')) {
            return;
        }

        Schema::create('default_chatbots', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('type')->nullable();
            $table->string('text')->nullable();
            $table->string('template_name')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('default_chatbots');
    }
};
