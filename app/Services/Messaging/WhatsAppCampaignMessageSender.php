<?php

namespace App\Services\Messaging;

use App\Services\Meta\MetaGraphClient;

class WhatsAppCampaignMessageSender
{
    public function __construct(
        protected WhatsAppMessagePayloadService $payloadService,
        protected MetaGraphClient $graph,
    ) {}

    /**
     * @param  array<string, mixed>  $context  Cache payload from send-campaign
     * @return array{success: bool, message_id: ?string, error: ?string, meta: ?array}
     */
    public function send(string $to, string $messagesApi, string $waToken, array $context): array
    {
        $campaignType = $context['campaign_type'] ?? 'custom';
        $isTemplate = $campaignType === 'template';

        $templateLanguage = (string) ($context['template_language'] ?? 'en_US');
        $templateBlocks = is_array($context['template_blocks'] ?? null) ? $context['template_blocks'] : [];
        $bodyValues = is_array($context['body_values'] ?? null) ? array_values($context['body_values']) : [];
        $buttonValues = $context['button_value'] ?? [];
        if (! is_array($buttonValues)) {
            $buttonValues = $buttonValues !== null && $buttonValues !== '' ? [$buttonValues] : [];
        } else {
            $buttonValues = array_values($buttonValues);
        }

        $headerMediaUrl = $context['header_media_url'] ?? null;
        $mediaId = $context['header_media_id'] ?? null;
        $effectiveMedia = filled($mediaId) ? (string) $mediaId : (filled($headerMediaUrl) ? (string) $headerMediaUrl : null);
        $resolvedMediaType = filled($mediaId) ? 'id' : (($headerMediaUrl !== null && $headerMediaUrl !== '') && is_numeric($headerMediaUrl) ? 'id' : 'link');

        $payload = $this->payloadService->generate(
            (string) $to,
            $isTemplate ? 'template' : 'custom',
            $context['template_name'] ?? null,
            $templateLanguage,
            $templateBlocks,
            $context['custom_text'] ?? null,
            $bodyValues,
            $buttonValues,
            $isTemplate ? null : 'text',
            $effectiveMedia,
            $resolvedMediaType,
            $context['header_file_name'] ?? null,
            []
        );

        $result = $this->graph->post($messagesApi, $waToken, $payload);
        $responseData = $result['body'];
        $statusCode = $result['status'];

        if (isset($responseData['messages'][0]['id'])) {
            return [
                'success' => true,
                'message_id' => (string) $responseData['messages'][0]['id'],
                'error' => null,
                'meta' => null,
            ];
        }

        $metaError = $responseData['error'] ?? null;

        return [
            'success' => false,
            'message_id' => null,
            'error' => is_array($metaError) ? ($metaError['message'] ?? 'Meta API error') : 'Message send failed',
            'meta' => is_array($metaError) ? $metaError : ['raw' => $responseData, 'status' => $statusCode],
        ];
    }
}
