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
        Schema::table('out_reports', function (Blueprint $table) {
            $table->timestamp('read_time')->nullable();
            $table->timestamp('delivered_time')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('out_reports', function (Blueprint $table) {
            //
        });
    }
};
