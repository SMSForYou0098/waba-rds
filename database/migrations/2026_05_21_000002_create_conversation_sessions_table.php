<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversation_sessions')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        Schema::create('conversation_sessions', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('wa_id', 32);
            $table->string('display_phone_number', 32)->nullable();
            $table->foreignId('flow_version_id')->constrained('chatbot_flow_versions')->cascadeOnDelete();
            $table->string('current_node_id', 36)->nullable();
            $table->boolean('awaiting_input')->default(false);
            if ($driver === 'pgsql') {
                $table->jsonb('vars')->default('{}');
                $table->jsonb('meta')->nullable();
            } else {
                $table->json('vars')->default('{}');
                $table->json('meta')->nullable();
            }
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'wa_id'], 'conversation_sessions_user_wa_unique');
            $table->index('flow_version_id', 'conversation_sessions_flow_version_id_idx');
            $table->index('expires_at', 'conversation_sessions_expires_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_sessions');
    }
};
