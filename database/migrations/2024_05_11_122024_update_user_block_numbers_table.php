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
        if (!Schema::hasTable('user_block_numbers') || Schema::hasColumn('user_block_numbers', 'chatbot_access')) {
            return;
        }

        Schema::table('user_block_numbers', function (Blueprint $table) {
            $table->integer('chatbot_access')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_block_numbers', function (Blueprint $table) {
            //
        });
    }
};
