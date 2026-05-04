<?php

namespace App\Console\Commands\Billing;

use App\Models\Billing\Plan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Billing\UserPlan;
use Spatie\Permission\Models\Role;

class ExpirePlans extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plans:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire user plans that have passed their expiration date';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting plan expiration process...');

        $expired = UserPlan::where('status', 'active')
            ->where('expires_at', '<', now())
            ->lockForUpdate()
            ->get();

        $expiredCount = $expired->count();

        if ($expiredCount === 0) {
            $this->info('No expired plans found.');
            return 0;
        }

        $this->info("Found {$expiredCount} expired plans to process.");

        foreach ($expired as $userPlan) {
            DB::transaction(function () use ($userPlan) {
                $user = $userPlan->user;

                // Remove role mapped to this plan only
                $planRoleId = $userPlan->role_id;
                if ($planRoleId) {
                    $planRole = Role::find($planRoleId);
                    if ($planRole) {
                        $user->removeRole($planRole->name);
                        $this->line("Removed role '{$planRole->name}' from user {$user->id}");
                    }
                }

                $userPlan->status = 'expired';
                $userPlan->save();

                // Optionally assign default free plan role:
                $freePlan = Plan::where('title', 'free')->first();
                if ($freePlan) $user->assignRole($freePlan->role->name);

                // Log change
                DB::table('plan_change_logs')->insert([
                    'user_id' => $user->id,
                    'from_plan_id' => $userPlan->plan_id,
                    'to_plan_id' => $freePlan->id ?? null,
                    'changed_by' => null,
                    'reason' => 'expired',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->line("Expired plan for user {$user->id}");
            });
        }

        $this->info("Successfully processed {$expiredCount} expired plans.");
        return 0;
    }
}
