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
        Schema::table('userconfigs', function (Blueprint $table) {
            $table->string('whatsapp_business_account_id')->nullable();
            $table->string('business_account_id')->nullable();
            $table->string('meta_access_token')->nullable();
            $table->string('whatsapp_phone_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('userconfigs', function (Blueprint $table) {
            $table->removeColumn('business_number');
            $table->removeColumn('business_id');
        });
    }
};
