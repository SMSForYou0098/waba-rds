<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('userconfigs', function (Blueprint $table) {
            $table->string('onboarding_status', 32)->nullable()->after('whatsapp_phone_id');
        });
    }

    public function down(): void
    {
        Schema::table('userconfigs', function (Blueprint $table) {
            $table->dropColumn('onboarding_status');
        });
    }
};
