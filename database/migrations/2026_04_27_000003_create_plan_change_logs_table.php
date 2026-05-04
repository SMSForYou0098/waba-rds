<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plan_change_logs')) {
            return;
        }

        Schema::create('plan_change_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('from_plan_id')->nullable();
            $table->unsignedBigInteger('to_plan_id')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('from_plan_id');
            $table->index('to_plan_id');
            $table->index('changed_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_change_logs');
    }
};

