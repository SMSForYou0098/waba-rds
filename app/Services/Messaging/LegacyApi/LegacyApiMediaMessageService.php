<?php

namespace App\Services\Messaging\LegacyApi;

use App\Data\Messaging\LegacyApiSendContext;
use App\Exceptions\Messaging\LegacyApiValidationException;
use App\Http\Requests\Messaging\SendWhatsAppMediaMessageRequest;
use App\Traits\ResolvesApiKeyTenant;

class LegacyApiMediaMessageService
{
    use ResolvesApiKeyTenant;

    public function __construct(
        private readonly LegacyApiMediaUploadService $mediaUploadService,
        private readonly LegacyApiMessageDispatchService $dispatchService,
        private readonly LegacyApiSendPreparationService $preparationService,
    ) {}

    /**
     * @return array{ok: bool, http_status: int, error?: string, error_code?: string, status?: bool, message?: string}
     */
    public function send(SendWhatsAppMediaMessageRequest $request): array
    {
        $apiKey = $this->resolveActiveApiKey((string) $request->query('apikey'));
        $this->assertApiKeyIpAllowed($apiKey, $request->ip());

        $user = $this->tenantUser($apiKey);
        $this->assertWhatsAppConfigured($user);

        $user->loadMissing('latestBalance');
        if ((float) ($user->latestBalance?->total_credits ?? 0) < 1) {
            throw new LegacyApiValidationException(
                'SF1',
                'Insufficient Credits to send a message. Please recharge your account to use our API smoothly. Thank You',
                401
            );
        }

        $to = (string) $request->query('to');
        if (! is_numeric($to) || ! in_array(strlen($to), [10, 12], true)) {
            throw new LegacyApiValidationException('SF2', 'Invalid Mobile number', 401);
        }

        $mediaFile = $request->file('file');
        if (! $mediaFile) {
            throw new LegacyApiValidationException('VAL006', 'Please choose your file', 401);
        }

        $config = $user->userConfig;
        $phoneId = (string) $config->whatsapp_phone_id;
        $wabaId = $config->whatsapp_business_account_id ? (string) $config->whatsapp_business_account_id : null;
        $waToken = (string) $config->meta_access_token;

        $this->preparationService->assertBulkBalance($user->id, 'C', 'marketing', [$to]);

        $uploaded = $this->mediaUploadService->uploadDocument($phoneId, $waToken, $mediaFile);

        $context = new LegacyApiSendContext(
            apiKey: $apiKey,
            reqType: 'C',
            message: null,
            templateName: null,
            mediaLink: null,
            mediaId: $uploaded['id'],
            fileName: $uploaded['filename'],
            bodyValues: [],
            buttonValues: [],
            templateBlocks: null,
            templateLanguage: 'en_US',
            billingCategory: 'marketing',
            phoneId: $phoneId,
            waToken: $waToken,
            wabaId: $wabaId,
            reportId: null,
            recipients: [$to],
        );

        $result = $this->dispatchService->sendDocument(
            $context,
            $to,
            $uploaded['id'],
            $uploaded['filename'],
        );

        if ($result['ok']) {
            return [
                'ok' => true,
                'http_status' => 200,
                'status' => true,
                'message' => 'Message submitted successfully',
            ];
        }

        return [
            'ok' => false,
            'http_status' => $result['http_status'],
            'status' => false,
            'error' => $result['error'] ?? 'API request failed',
            'error_code' => $result['error_code'] ?? 'MESSAGE_SENDING_ERROR',
        ];
    }
}
