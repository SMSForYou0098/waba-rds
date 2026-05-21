<?php

namespace App\Services\Billing;

use App\Models\Billing\PricingModel;
use App\Models\User;
use App\Services\Messaging\WhatsAppTemplateResolver;
use Illuminate\Validation\ValidationException;

class MessagePricingResolver
{
    private const BILLING_CATEGORIES = ['marketing', 'utility', 'authentication', 'service'];

    public function __construct(
        private readonly CampaignPricingService $campaignPricingService,
        private readonly WhatsAppTemplateResolver $templateResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  Meta send-message body
     * @return array{
     *     pricing_found: bool,
     *     unit_price: float,
     *     category: string,
     *     message: ?string
     * }
     */
    public function resolve(User $user, array $payload, ?string $wabaId, ?string $waToken, ?string $billingCategoryOverride = null): array
    {
        $pricing = PricingModel::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->first();

        if (! $pricing) {
            return [
                'pricing_found' => false,
                'unit_price' => 0.0,
                'category' => 'marketing',
                'message' => 'Pricing is not configured for this account.',
            ];
        }

        $category = $this->resolveCategory($payload, $wabaId, $waToken, $billingCategoryOverride);
        $messageType = strtolower((string) ($payload['type'] ?? 'text'));

        $unitPrice = $messageType === 'template'
            ? $this->campaignPricingService->unitPriceForTemplateCategory($pricing, $category)
            : (float) ($pricing->marketing_price ?? 0);

        if ($unitPrice <= 0) {
            return [
                'pricing_found' => false,
                'unit_price' => 0.0,
                'category' => $category,
                'message' => 'Invalid or zero price for this message type.',
            ];
        }

        return [
            'pricing_found' => true,
            'unit_price' => round($unitPrice, 2),
            'category' => $category,
            'message' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCategory(
        array $payload,
        ?string $wabaId,
        ?string $waToken,
        ?string $billingCategoryOverride
    ): string {
        if ($billingCategoryOverride !== null && $billingCategoryOverride !== '') {
            $normalized = strtolower(trim($billingCategoryOverride));
            if (! in_array($normalized, self::BILLING_CATEGORIES, true)) {
                throw ValidationException::withMessages([
                    'billing_category' => ['billing_category must be one of: '.implode(', ', self::BILLING_CATEGORIES)],
                ]);
            }

            return $normalized;
        }

        $messageType = strtolower((string) ($payload['type'] ?? 'text'));
        if ($messageType !== 'template') {
            return 'marketing';
        }

        $templateName = (string) ($payload['template']['name'] ?? '');
        if ($templateName === '' || ! $wabaId || ! $waToken) {
            return 'marketing';
        }

        $resolved = $this->templateResolver->resolve($wabaId, $waToken, $templateName);
        if (isset($resolved['error'])) {
            return 'marketing';
        }

        return (string) ($resolved['category'] ?? 'marketing');
    }
}
