<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('balances', function (Blueprint $table) {
            if (!Schema::hasColumn('balances', 'report_id')) {
                $table->unsignedBigInteger('report_id')->nullable();
                $table->index('report_id');
            }

            if (!Schema::hasColumn('balances', 'remarks')) {
                $table->text('remarks')->nullable();
            }

            if (!Schema::hasColumn('balances', 'duplicate_count')) {
                $table->unsignedInteger('duplicate_count')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('balances', function (Blueprint $table) {
            //
        });
    }
};

