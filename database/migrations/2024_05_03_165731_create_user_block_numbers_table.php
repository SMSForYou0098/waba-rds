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
        if (Schema::hasTable('user_block_numbers')) {
            return;
        }

        Schema::create('user_block_numbers', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->longText('numbers')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_block_numbers');
    }
};
