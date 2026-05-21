<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical ordering in this repo (single shared DB, full Waba migration stack):
 *
 * - pricing_models created in 2024_03_12_090106_create_pricing_models_table (legacy *_prize names).
 * - user_id cast to bigint in 2026_04_29_000007_alter_pricing_models_user_id_to_bigint (does not rename price columns).
 * - balances.alert_credit remains NOT NULL in 2024_03_11_* until this migration.
 *
 * This file is the only migration that renames *_prize → *_price and adds service/authentication columns.
 * It is idempotent: safe on DBs already manually aligned to production, and safe to run once per environment
 * (migrate records batch — it will not re-execute after success).
 *
 * Does not overlap 2026_04_30_000021_fix_remaining_internal_foreign_key_types (balances numeric FK columns only).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        $this->alignPricingModels();
        $this->nullableBalancesAlertCredit($driver);
    }

    private function alignPricingModels(): void
    {
        if (! Schema::hasTable('pricing_models')) {
            return;
        }

        if (Schema::hasColumn('pricing_models', 'marketing_prize')
            && ! Schema::hasColumn('pricing_models', 'marketing_price')) {
            Schema::table('pricing_models', function (Blueprint $table) {
                $table->renameColumn('marketing_prize', 'marketing_price');
            });
        }

        if (Schema::hasColumn('pricing_models', 'utility_prize')
            && ! Schema::hasColumn('pricing_models', 'utility_price')) {
            Schema::table('pricing_models', function (Blueprint $table) {
                $table->renameColumn('utility_prize', 'utility_price');
            });
        }

        Schema::table('pricing_models', function (Blueprint $table) {
            if (! Schema::hasColumn('pricing_models', 'service_price')) {
                $table->decimal('service_price', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('pricing_models', 'authentication_price')) {
                $table->decimal('authentication_price', 10, 2)->nullable();
            }
        });
    }

    private function nullableBalancesAlertCredit(string $driver): void
    {
        if (! Schema::hasTable('balances') || ! Schema::hasColumn('balances', 'alert_credit')) {
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE balances ALTER COLUMN alert_credit DROP NOT NULL');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE balances MODIFY alert_credit DOUBLE NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('pricing_models')) {
            Schema::table('pricing_models', function (Blueprint $table) {
                if (Schema::hasColumn('pricing_models', 'authentication_price')) {
                    $table->dropColumn('authentication_price');
                }
                if (Schema::hasColumn('pricing_models', 'service_price')) {
                    $table->dropColumn('service_price');
                }
            });

            if (Schema::hasColumn('pricing_models', 'marketing_price')
                && ! Schema::hasColumn('pricing_models', 'marketing_prize')) {
                Schema::table('pricing_models', function (Blueprint $table) {
                    $table->renameColumn('marketing_price', 'marketing_prize');
                });
            }
            if (Schema::hasColumn('pricing_models', 'utility_price')
                && ! Schema::hasColumn('pricing_models', 'utility_prize')) {
                Schema::table('pricing_models', function (Blueprint $table) {
                    $table->renameColumn('utility_price', 'utility_prize');
                });
            }
        }
    }
};
