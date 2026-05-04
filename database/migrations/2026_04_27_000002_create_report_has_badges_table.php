<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('report_has_badges')) {
            return;
        }

        Schema::create('report_has_badges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('report_id');
            $table->unsignedBigInteger('badge_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->index(['badge_id', 'report_id']);
            $table->index('user_id');
            $table->unique(['report_id', 'badge_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_has_badges');
    }
};

