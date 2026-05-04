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
        if (!Schema::hasTable('chatbots')) {
            return;
        }

        Schema::table('chatbots', function (Blueprint $table) {
           if (!Schema::hasColumn('chatbots', 'reply_template_media')) {
               $table->text('reply_template_media')->before('created_at');
           }
           if (!Schema::hasColumn('chatbots', 'json_true_template_media')) {
               $table->text('json_true_template_media')->before('created_at');
           }
           if (!Schema::hasColumn('chatbots', 'json_false_template_media')) {
               $table->text('json_false_template_media')->before('created_at');
           }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            //
        });
    }
};
