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
        if (Schema::hasTable('balances')) {
            return;
        }

        Schema::create('balances', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->float('total_credits');
            $table->float('alert_credit');
            $table->string('payment_type');
            $table->string('account_manager_id');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('balances');
    }
};
