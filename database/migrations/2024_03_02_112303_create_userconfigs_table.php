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
        if (Schema::hasTable('userconfigs')) {
            return;
        }

        Schema::create('userconfigs', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('app_id');
            $table->string('business_number');
            $table->string('business_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('userconfigs');
    }
};
