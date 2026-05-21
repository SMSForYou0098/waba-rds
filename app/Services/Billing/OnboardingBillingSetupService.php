<?php

namespace App\Services\Billing;

use App\Models\Billing\Balance;
use App\Models\Billing\PricingModel;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class OnboardingBillingSetupService
{
    /**
     * Steps 5–7 after WhatsApp onboarding: role, starter balance, default pricing (sequential).
     *
     * @return array<string, mixed>
     */
    public function setup(User $user): array
    {
        $roleResult = $this->promoteViewerToUserRole($user);

        if (! ($roleResult['ok'] ?? false)) {
            return $roleResult;
        }

        $balanceResult = $this->applyStarterBalance($user);

        if (! ($balanceResult['ok'] ?? false)) {
            return $balanceResult;
        }

        $pricingResult = $this->applyDefaultPricing($user);

        if (! ($pricingResult['ok'] ?? false)) {
            return $pricingResult;
        }

        return [
            'ok'                 => true,
            'role_changed'       => $roleResult['role_changed'] ?? false,
            'role'               => $roleResult['role'] ?? null,
            'starter_balance'    => $balanceResult['amount'],
            'balance_skipped'    => $balanceResult['skipped'] ?? false,
            'pricing_configured' => true,
        ];
    }

    /**
     * Same as RolePermissionController::changeViewerRole — only when current role is Viewer.
     *
     * @return array<string, mixed>
     */
    private function promoteViewerToUserRole(User $user): array
    {
        try {
            if (! $user->hasRole('Viewer')) {
                return [
                    'ok'           => true,
                    'role_changed' => false,
                    'role'         => $user->getRoleNames()->first(),
                ];
            }

            $userRole = Role::where('name', 'User')->first();

            if ($userRole === null) {
                return $this->fail(5, 'Assign User Role', 'User role not found in database');
            }

            DB::transaction(function () use ($user, $userRole) {
                $user->roles()->detach();
                $user->assignRole($userRole);
            });

            return [
                'ok'           => true,
                'role_changed' => true,
                'role'         => 'User',
            ];
        } catch (\Throwable $e) {
            return $this->fail(5, 'Assign User Role', $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function applyStarterBalance(User $user): array
    {
        try {
            $amount = (float) env('ONBOARDING_STARTER_BALANCE', 10);

            if ($amount <= 0) {
                return $this->fail(6, 'Starter Balance', 'ONBOARDING_STARTER_BALANCE must be greater than zero');
            }

            $hasBalance = Balance::where('user_id', $user->id)->exists();

            if ($hasBalance) {
                return [
                    'ok'      => true,
                    'amount'  => $amount,
                    'skipped' => true,
                ];
            }

            DB::transaction(function () use ($user, $amount) {
                $balance = new Balance();
                $balance->user_id = $user->id;
                $balance->new_credit = $amount;
                $balance->total_credits = $amount;
                $balance->payment_type = 'cash';
                $balance->account_manager_id = $user->reporting_user;
                $balance->save();
            });

            Cache::forget("user.{$user->id}.latest_balance");
            Cache::forget("user.{$user->id}.credit_history");
            Cache::forget('admin.all_credit_history');

            return [
                'ok'     => true,
                'amount' => $amount,
            ];
        } catch (\Throwable $e) {
            return $this->fail(6, 'Starter Balance', $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function applyDefaultPricing(User $user): array
    {
        try {
            $attributes = [
                'price_alert'           => (float) env('ONBOARDING_PRICE_ALERT', 10),
                'marketing_price'       => (float) env('ONBOARDING_MARKETING_PRICE', 0.8),
                'utility_price'         => (float) env('ONBOARDING_UTILITY_PRICE', 0.2),
                'service_price'         => (float) env('ONBOARDING_SERVICE_PRICE', 0.35),
                'authentication_price'  => (float) env('ONBOARDING_AUTHENTICATION_PRICE', 0.2),
            ];

            DB::transaction(function () use ($user, $attributes) {
                PricingModel::updateOrCreate(
                    ['user_id' => $user->id],
                    $attributes
                );
            });

            return ['ok' => true];
        } catch (\Throwable $e) {
            return $this->fail(7, 'Pricing Model', $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fail(int $step, string $label, string $message): array
    {
        return [
            'ok'         => false,
            'failed_at'  => $step,
            'step_label' => $label,
            'error'      => $message,
        ];
    }
}
