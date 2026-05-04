<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_histories', function (Blueprint $table) {
            if (!Schema::hasColumn('chat_histories', 'message_id')) {
                $table->string('message_id')->nullable();
                $table->index('message_id');
            }

            if (!Schema::hasColumn('chat_histories', 'type')) {
                $table->string('type')->nullable();
                $table->index('type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_histories', function (Blueprint $table) {
            //
        });
    }
};

