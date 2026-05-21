<?php

namespace App\Console\Commands\Billing;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ArchiveBalancesCommand extends Command
{
    protected $signature = 'balances:archive
                            {--dry-run : Count eligible rows without writing}
                            {--batch= : Override batch size from config}';

    protected $description = 'Archive old balance rows to balances_archive (keeps latest row per user in hot table)';

    public function handle(): int
    {
        $retentionDays = (int) config('billing.archive.retention_days', 90);
        $batchSize = (int) ($this->option('batch') ?: config('billing.archive.batch_size', 5000));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($retentionDays);

        $this->info("Retention: {$retentionDays} days (before {$cutoff->toDateTimeString()})");
        $this->info('Batch size: '.$batchSize.($dryRun ? ' [DRY RUN]' : ''));

        $totalArchived = 0;

        while (true) {
            $ids = $this->eligibleIds($cutoff, $batchSize);

            if ($ids === []) {
                break;
            }

            if ($dryRun) {
                $totalArchived += count($ids);
                if (count($ids) < $batchSize) {
                    break;
                }

                continue;
            }

            $archived = DB::transaction(function () use ($ids): int {
                $rows = DB::table('balances')->whereIn('id', $ids)->get();

                if ($rows->isEmpty()) {
                    return 0;
                }

                $now = now();
                $inserts = $rows->map(function ($row) use ($now) {
                    return [
                        'original_id' => $row->id,
                        'user_id' => $row->user_id,
                        'total_credits' => $row->total_credits,
                        'alert_credit' => $row->alert_credit ?? null,
                        'new_credit' => $row->new_credit,
                        'payment_type' => $row->payment_type,
                        'account_manager_id' => $row->account_manager_id,
                        'manual_deduction' => $row->manual_deduction,
                        'auto_deduction' => $row->auto_deduction,
                        'report_id' => $row->report_id,
                        'remarks' => $row->remarks,
                        'duplicate_count' => $row->duplicate_count ?? 0,
                        'archived_at' => $now,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ];
                })->all();

                DB::table('balances_archive')->insert($inserts);
                DB::table('balances')->whereIn('id', $ids)->delete();

                return count($ids);
            });

            $totalArchived += $archived;
            $this->line("Archived {$archived} row(s)...");

            if ($archived < $batchSize) {
                break;
            }
        }

        if ($dryRun) {
            $this->info("Dry run complete. Eligible rows in first batch: {$totalArchived}");
        } else {
            $this->info("Archive complete. Total rows archived: {$totalArchived}");
            Cache::forget('admin.all_credit_history');
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function eligibleIds(CarbonInterface $cutoff, int $limit): array
    {
        return DB::table('balances as b')
            ->where('b.created_at', '<', $cutoff)
            ->whereNotIn('b.id', function ($query) {
                $query->selectRaw('MAX(id)')->from('balances')->groupBy('user_id');
            })
            ->orderBy('b.id')
            ->limit($limit)
            ->pluck('b.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
