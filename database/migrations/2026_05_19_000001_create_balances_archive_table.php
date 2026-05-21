<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('balances_archive')) {
            Schema::create('balances_archive', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('original_id')->unique();
                $table->unsignedBigInteger('user_id');
                $table->double('total_credits')->nullable();
                $table->double('alert_credit')->nullable();
                $table->double('new_credit')->nullable();
                $table->string('payment_type')->nullable();
                $table->unsignedBigInteger('account_manager_id')->nullable();
                $table->string('manual_deduction')->nullable();
                $table->string('auto_deduction')->nullable();
                $table->unsignedBigInteger('report_id')->nullable();
                $table->text('remarks')->nullable();
                $table->unsignedInteger('duplicate_count')->default(0);
                $table->timestamp('archived_at')->useCurrent();
                $table->timestamps();

                $table->index(['user_id', 'created_at']);
            });
        }

        Schema::table('balances', function (Blueprint $table) {
            if (! $this->indexExists('balances', 'balances_user_id_id_desc_index')) {
                $table->index(['user_id', 'id'], 'balances_user_id_id_desc_index');
            }
            if (! $this->indexExists('balances', 'balances_created_at_index')) {
                $table->index('created_at', 'balances_created_at_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('balances', function (Blueprint $table) {
            if ($this->indexExists('balances', 'balances_user_id_id_desc_index')) {
                $table->dropIndex('balances_user_id_id_desc_index');
            }
            if ($this->indexExists('balances', 'balances_created_at_index')) {
                $table->dropIndex('balances_created_at_index');
            }
        });

        Schema::dropIfExists('balances_archive');
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            $result = $connection->selectOne(
                'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $indexName]
            );

            return $result !== null;
        }

        if ($driver === 'mysql') {
            $result = $connection->selectOne(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$table, $indexName]
            );

            return $result !== null;
        }

        return false;
    }
};
