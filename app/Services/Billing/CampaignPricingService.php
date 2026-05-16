<?php

namespace App\Services\Billing;

use App\Models\Billing\PricingModel;
use App\Models\User;

class CampaignPricingService
{
    /**
     * Per-message price for a WhatsApp template category (matches legacy UserController logic).
     *
     * @param  object|PricingModel  $pricing  Row with marketing_price, utility_price, etc.
     */
    public function unitPriceForTemplateCategory(object $pricing, ?string $templateCategory): float
    {
        $category = $templateCategory !== null && $templateCategory !== ''
            ? strtolower((string) $templateCategory)
            : '';

        return match ($category) {
            'marketing' => (float) ($pricing->marketing_price ?? 0),
            'utility' => (float) ($pricing->utility_price ?? 0),
            'authentication' => (float) ($pricing->authentication_price ?? 0),
            'service' => (float) ($pricing->service_price ?? 0),
            default => (float) ($pricing->marketing_price ?? 0),
        };
    }

    /**
     * Custom/session-style sends are estimated at marketing_price per recipient (same as single-send checks elsewhere).
     *
     * @param  object|PricingModel  $pricing
     */
    public function unitPriceForBulkCampaign(string $campaignType, ?string $templateCategory, object $pricing): float
    {
        $type = strtolower($campaignType);

        if ($type === 'custom') {
            return (float) ($pricing->marketing_price ?? 0);
        }

        return $this->unitPriceForTemplateCategory($pricing, $templateCategory);
    }

    /**
     * @return array{
     *     user_found: bool,
     *     pricing_found: bool,
     *     message: ?string,
     *     current_balance: float,
     *     unit_price: float,
     *     balance_required: float,
     *     can_proceed: bool
     * }
     */
    public function estimateBulkSend(int $userId, int $numbersCount, string $campaignType, ?string $templateCategory): array
    {
        $user = User::query()->find($userId);

        if (! $user) {
            return [
                'user_found' => false,
                'pricing_found' => false,
                'message' => 'User not found.',
                'current_balance' => 0.0,
                'unit_price' => 0.0,
                'balance_required' => 0.0,
                'can_proceed' => false,
            ];
        }

        $pricing = PricingModel::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->first();

        if (! $pricing) {
            $balance = (float) ($user->latestBalance?->total_credits ?? 0);

            return [
                'user_found' => true,
                'pricing_found' => false,
                'message' => 'Pricing is not configured for this account.',
                'current_balance' => $balance,
                'unit_price' => 0.0,
                'balance_required' => 0.0,
                'can_proceed' => false,
            ];
        }

        $unitPrice = $this->unitPriceForBulkCampaign($campaignType, $templateCategory, $pricing);
        $currentBalance = (float) ($user->latestBalance?->total_credits ?? 0);
        $balanceRequired = round($unitPrice * $numbersCount, 2);

        $canProceed = $currentBalance >= $balanceRequired;

        return [
            'user_found' => true,
            'pricing_found' => true,
            'message' => $canProceed ? null : 'Insufficient credits for this campaign size.',
            'current_balance' => $currentBalance,
            'unit_price' => $unitPrice,
            'balance_required' => $balanceRequired,
            'can_proceed' => $canProceed,
        ];
    }
}
