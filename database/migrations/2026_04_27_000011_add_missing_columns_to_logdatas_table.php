<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logdatas', function (Blueprint $table) {
            if (!Schema::hasColumn('logdatas', 'display_phone_number')) {
                $table->string('display_phone_number')->nullable();
                $table->index('display_phone_number');
            }

            if (!Schema::hasColumn('logdatas', 'message_id')) {
                $table->string('message_id')->nullable();
                $table->index('message_id');
            }

            if (!Schema::hasColumn('logdatas', 'record_count')) {
                $table->unsignedInteger('record_count')->default(0);
            }

            if (!Schema::hasColumn('logdatas', 'reprocessed_at')) {
                $table->timestamp('reprocessed_at')->nullable();
                $table->index('reprocessed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('logdatas', function (Blueprint $table) {
            //
        });
    }
};

