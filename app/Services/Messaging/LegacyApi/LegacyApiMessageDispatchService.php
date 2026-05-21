<?php

namespace App\Services\Messaging\LegacyApi;

use App\Data\Messaging\LegacyApiSendContext;
use App\Exceptions\Billing\InsufficientCreditsException;
use App\Exceptions\Billing\MessageAlreadyBilledException;
use App\Exceptions\Billing\PricingNotConfiguredException;
use App\Services\Messaging\OutboundMessageSendService;

class LegacyApiMessageDispatchService
{
    public function __construct(
        private readonly OutboundMessageSendService $outboundSendService,
        private readonly LegacyApiPayloadBuilder $payloadBuilder,
        private readonly LegacyApiReportSideEffectsService $sideEffects,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     http_status: int,
     *     message_id?: string,
     *     error?: string,
     *     error_code?: string,
     *     error_type?: string
     * }
     */
    public function sendToRecipient(LegacyApiSendContext $context, string $to): array
    {
        $payload = $this->payloadBuilder->buildForRecipient($context, $to);
        $user = $context->apiKey->user;

        try {
            $result = $this->outboundSendService->sendAndBill(
                $user,
                $payload,
                $context->phoneId,
                $context->waToken,
                $context->wabaId,
                $context->billingCategory,
            );

            if ($result['ok'] && $result['wamid'] !== null && $result['out_report_id'] !== null) {
                $this->sideEffects->record(
                    $result['out_report_id'],
                    $user->id,
                    $context->templateName,
                    $result['wamid'],
                    $context->mediaId,
                    $context->mediaLink,
                    $context->bodyValues,
                    $context->buttonValues,
                    $context->reportId,
                );

                return [
                    'ok' => true,
                    'http_status' => 200,
                    'message_id' => encrypt($result['wamid']),
                ];
            }

            return [
                'ok' => false,
                'http_status' => $result['http'] ?? 400,
                'error' => $result['error'] ?? 'Message sending failed',
                'error_code' => $result['code'] ?? 'MESSAGE_SENDING_ERROR',
                'error_type' => 'sending_error',
            ];
        } catch (InsufficientCreditsException) {
            return [
                'ok' => false,
                'http_status' => 401,
                'error' => 'Insufficient Credits to send a message. Please recharge your account to use our API smoothly. Thank You',
                'error_code' => 'SF1',
                'error_type' => 'billing_error',
            ];
        } catch (PricingNotConfiguredException $e) {
            return [
                'ok' => false,
                'http_status' => 422,
                'error' => $e->getMessage(),
                'error_code' => 'SF8',
                'error_type' => 'billing_error',
            ];
        } catch (MessageAlreadyBilledException $e) {
            return [
                'ok' => true,
                'http_status' => 200,
                'message_id' => encrypt($e->wamid),
            ];
        }
    }

    /**
     * @return array{ok: bool, http_status: int, error?: string, error_code?: string}
     */
    public function sendDocument(
        LegacyApiSendContext $context,
        string $to,
        string $mediaId,
        string $fileName,
    ): array {
        $payload = $this->payloadBuilder->buildDocumentPayload($to, $mediaId, $fileName);
        $user = $context->apiKey->user;

        try {
            $result = $this->outboundSendService->sendAndBill(
                $user,
                $payload,
                $context->phoneId,
                $context->waToken,
                $context->wabaId,
            );

            if ($result['ok'] && $result['wamid'] !== null && $result['out_report_id'] !== null) {
                $this->sideEffects->record(
                    $result['out_report_id'],
                    $user->id,
                    null,
                    $result['wamid'],
                    $mediaId,
                    null,
                    null,
                    null,
                    null,
                );

                return ['ok' => true, 'http_status' => 200];
            }

            return [
                'ok' => false,
                'http_status' => $result['http'] ?? 400,
                'error' => $result['error'] ?? 'API request failed',
                'error_code' => $result['code'] ?? 'MESSAGE_SENDING_ERROR',
            ];
        } catch (InsufficientCreditsException) {
            return [
                'ok' => false,
                'http_status' => 401,
                'error' => 'Insufficient Credits to send a message. Please recharge your account to use our API smoothly. Thank You',
                'error_code' => 'SF1',
            ];
        } catch (PricingNotConfiguredException $e) {
            return [
                'ok' => false,
                'http_status' => 422,
                'error' => $e->getMessage(),
                'error_code' => 'SF8',
            ];
        } catch (MessageAlreadyBilledException) {
            return ['ok' => true, 'http_status' => 200];
        }
    }
}
