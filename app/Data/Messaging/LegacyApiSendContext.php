<?php

namespace App\Data\Messaging;

use App\Models\Auth\ApiKey;

/**
 * Prepared state for legacy API-key bulk / single sends (after validation + template resolve).
 */
readonly class LegacyApiSendContext
{
    /**
     * @param  list<string>  $recipients  Normalized destination numbers (10 or 12 digits)
     * @param  array<string, mixed>|null  $templateBlocks  header, body, footer, buttons
     * @param  list<string>  $bodyValues
     * @param  list<string>  $buttonValues
     */
    public function __construct(
        public ApiKey $apiKey,
        public string $reqType,
        public ?string $message,
        public ?string $templateName,
        public ?string $mediaLink,
        public ?string $mediaId,
        public ?string $fileName,
        public array $bodyValues,
        public array $buttonValues,
        public ?array $templateBlocks,
        public string $templateLanguage,
        public ?string $billingCategory,
        public string $phoneId,
        public string $waToken,
        public ?string $wabaId,
        public ?string $reportId,
        public array $recipients,
    ) {}

    public function isCustom(): bool
    {
        return $this->reqType === 'C';
    }
}
