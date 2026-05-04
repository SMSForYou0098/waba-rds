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
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'EP')) {
                $table->string('EP')->nullable();
            }
            if (!Schema::hasColumn('users', 'whatsapp_alerts')) {
                $table->string('whatsapp_alerts')->nullable();
            }
            if (!Schema::hasColumn('users', 'email_alerts')) {
                $table->string('email_alerts')->nullable();
            }
            if (!Schema::hasColumn('users', 'text_alerts')) {
                $table->string('text_alerts')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            //
        });
    }
};
