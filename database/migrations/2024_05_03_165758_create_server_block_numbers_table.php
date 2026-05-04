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
        if (Schema::hasTable('server_block_numbers')) {
            return;
        }

        Schema::create('server_block_numbers', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->longText('numbers')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_block_numbers');
    }
};
