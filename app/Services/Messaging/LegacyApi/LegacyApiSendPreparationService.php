<?php

namespace App\Services\Messaging\LegacyApi;

use App\Data\Messaging\LegacyApiSendContext;
use App\Exceptions\Messaging\LegacyApiValidationException;
use App\Http\Requests\Messaging\SendWhatsAppApiMessageRequest;
use App\Services\Billing\CampaignPricingService;
use App\Traits\ResolvesApiKeyTenant;
use Illuminate\Http\UploadedFile;

class LegacyApiSendPreparationService
{
    use ResolvesApiKeyTenant;

    public function __construct(
        private readonly LegacyApiTemplateService $templateService,
        private readonly LegacyApiMediaUploadService $mediaUploadService,
        private readonly CampaignPricingService $campaignPricingService,
    ) {}

    public function prepare(SendWhatsAppApiMessageRequest $request): LegacyApiSendContext
    {
        $apiKey = $this->resolveActiveApiKey((string) $request->input('apikey'));
        $this->assertApiKeyIpAllowed($apiKey, $request->ip());

        $user = $this->tenantUser($apiKey);
        $this->assertWhatsAppConfigured($user);

        $user->loadMissing('latestBalance');
        $balance = (float) ($user->latestBalance?->total_credits ?? 0);
        if ($balance < 1) {
            throw new LegacyApiValidationException(
                'SF1',
                'Insufficient Credits to send a message. Please recharge your account to use our API smoothly. Thank You',
                401
            );
        }

        $reqType = (string) $request->input('type');
        $message = $request->input('message');
        $templateName = $request->input('tname');
        $mediaLink = $request->input('media_url');
        $mediaId = $request->input('media_id');
        $fileName = $request->input('file_name');

        if ($reqType === 'C' && empty($message)) {
            throw new LegacyApiValidationException('SF3', 'Message content is required for custom messages', 400);
        }

        if ($reqType !== 'C' && empty($templateName)) {
            throw new LegacyApiValidationException('VAL004', 'Template name is required for template messages', 400);
        }

        $config = $user->userConfig;
        $phoneId = (string) $config->whatsapp_phone_id;
        $wabaId = $config->whatsapp_business_account_id ? (string) $config->whatsapp_business_account_id : null;
        $waToken = (string) $config->meta_access_token;

        $templateBlocks = null;
        $templateLanguage = 'en_US';
        $billingCategory = null;
        $bodyValues = [];
        $buttonValues = [];

        if ($reqType !== 'C') {
            $resolved = $this->templateService->resolve($wabaId ?? '', $waToken, (string) $templateName);
            $templateBlocks = $resolved['blocks'];
            $templateLanguage = $resolved['language'];
            $billingCategory = $resolved['category'];

            $bodyValues = $this->templateService->parseCommaList($request->input('values'));
            $buttonValues = $this->templateService->parseCommaList($request->input('button_value'));

            $this->templateService->validateTemplateInputs(
                $templateBlocks,
                $bodyValues,
                $buttonValues,
                $mediaLink ? (string) $mediaLink : null,
                $mediaId ? (string) $mediaId : null,
                $request->file('file'),
            );
        }

        /** @var UploadedFile|null $mediaFile */
        $mediaFile = $request->file('file');
        if ($mediaFile) {
            $uploaded = $this->mediaUploadService->uploadForTemplateHeader($phoneId, $waToken, $mediaFile);
            $mediaId = $uploaded['id'];
            $fileName = $uploaded['filename'];
        }

        $recipients = $this->normalizeRecipients((string) $request->input('to'));
        $this->assertBulkBalance($user->id, $reqType, $billingCategory, $recipients);

        return new LegacyApiSendContext(
            apiKey: $apiKey,
            reqType: $reqType,
            message: $message ? (string) $message : null,
            templateName: $templateName ? (string) $templateName : null,
            mediaLink: $mediaLink ? (string) $mediaLink : null,
            mediaId: $mediaId ? (string) $mediaId : null,
            fileName: $fileName ? (string) $fileName : null,
            bodyValues: $bodyValues,
            buttonValues: $buttonValues,
            templateBlocks: $templateBlocks,
            templateLanguage: $templateLanguage,
            billingCategory: $billingCategory,
            phoneId: $phoneId,
            waToken: $waToken,
            wabaId: $wabaId,
            reportId: $request->input('report_id') ? (string) $request->input('report_id') : null,
            recipients: $recipients,
        );
    }

    /**
     * @return list<string>
     */
    public function normalizeRecipients(string $to): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $to))));
    }

    /**
     * @param  list<string>  $recipients
     */
    public function assertBulkBalance(int $userId, string $reqType, ?string $billingCategory, array $recipients): void
    {
        $validCount = 0;
        foreach ($recipients as $to) {
            if ($to !== '' && is_numeric($to) && in_array(strlen($to), [10, 12], true)) {
                $validCount++;
            }
        }

        if ($validCount === 0) {
            return;
        }

        $campaignType = $reqType === 'C' ? 'custom' : 'template';
        $estimate = $this->campaignPricingService->estimateBulkSend(
            $userId,
            $validCount,
            $campaignType,
            $billingCategory,
        );

        if (! $estimate['can_proceed']) {
            throw new LegacyApiValidationException(
                'SF1',
                $estimate['message'] ?? 'Insufficient credits for this request.',
                401
            );
        }
    }
}
