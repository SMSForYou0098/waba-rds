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
        if (Schema::hasTable('pricing_models')) {
            return;
        }

        Schema::create('pricing_models', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->float('price_alert');
            $table->float('marketing_prize');
            $table->float('utility_prize');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_models');
    }
};
