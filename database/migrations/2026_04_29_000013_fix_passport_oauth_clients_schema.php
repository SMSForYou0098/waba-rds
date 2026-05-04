<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('oauth_clients')) {
            return;
        }

        Schema::table('oauth_clients', function (Blueprint $table) {
            if (!Schema::hasColumn('oauth_clients', 'grant_types')) {
                $table->text('grant_types')->nullable();
            }
            if (!Schema::hasColumn('oauth_clients', 'scopes')) {
                $table->text('scopes')->nullable();
            }
            if (!Schema::hasColumn('oauth_clients', 'redirect_uris')) {
                $table->text('redirect_uris')->nullable();
            }
            if (!Schema::hasColumn('oauth_clients', 'owner_type')) {
                $table->string('owner_type')->nullable();
            }
            if (!Schema::hasColumn('oauth_clients', 'owner_id')) {
                $table->unsignedBigInteger('owner_id')->nullable();
            }
        });

        // Ensure existing clients have explicit grant types for Passport v13 logic.
        DB::table('oauth_clients')
            ->where('personal_access_client', 1)
            ->update(['grant_types' => json_encode(['personal_access', 'refresh_token'])]);

        DB::table('oauth_clients')
            ->where('password_client', 1)
            ->update(['grant_types' => json_encode(['password', 'refresh_token'])]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('oauth_clients')) {
            return;
        }

        Schema::table('oauth_clients', function (Blueprint $table) {
            if (Schema::hasColumn('oauth_clients', 'owner_id')) {
                $table->dropColumn('owner_id');
            }
            if (Schema::hasColumn('oauth_clients', 'owner_type')) {
                $table->dropColumn('owner_type');
            }
            if (Schema::hasColumn('oauth_clients', 'redirect_uris')) {
                $table->dropColumn('redirect_uris');
            }
            if (Schema::hasColumn('oauth_clients', 'scopes')) {
                $table->dropColumn('scopes');
            }
            if (Schema::hasColumn('oauth_clients', 'grant_types')) {
                $table->dropColumn('grant_types');
            }
        });
    }
};
