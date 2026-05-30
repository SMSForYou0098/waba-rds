<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chatbot_flow_versions')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        Schema::create('chatbot_flow_versions', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('draft');
            if ($driver === 'pgsql') {
                $table->jsonb('definition');
            } else {
                $table->json('definition');
            }
            $table->boolean('is_active')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->unsignedBigInteger('legacy_group_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id', 'chatbot_flow_versions_user_id_idx');
            $table->index(['user_id', 'group_id', 'status'], 'chatbot_flow_versions_group_status_idx');
        });

        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX chatbot_flow_versions_active_group ON chatbot_flow_versions (user_id, group_id) WHERE is_active = true AND deleted_at IS NULL'
            );
            DB::statement(
                'CREATE INDEX chatbot_flow_versions_definition_gin ON chatbot_flow_versions USING GIN (definition)'
            );
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS chatbot_flow_versions_definition_gin');
            DB::statement('DROP INDEX IF EXISTS chatbot_flow_versions_active_group');
        }

        Schema::dropIfExists('chatbot_flow_versions');
    }
};
