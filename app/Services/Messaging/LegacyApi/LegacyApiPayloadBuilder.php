<?php

namespace App\Services\Messaging\LegacyApi;

use App\Data\Messaging\LegacyApiSendContext;
use App\Services\Messaging\WhatsAppMessagePayloadService;

class LegacyApiPayloadBuilder
{
    public function __construct(
        private readonly WhatsAppMessagePayloadService $messagePayloadService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildForRecipient(LegacyApiSendContext $context, string $to): array
    {
        $isTemplate = ! $context->isCustom();
        $template = $context->templateBlocks ?? [];

        $effectiveMedia = filled($context->mediaId)
            ? (string) $context->mediaId
            : (filled($context->mediaLink) ? (string) $context->mediaLink : null);

        $resolvedMediaType = filled($context->mediaId)
            ? 'id'
            : (($context->mediaLink !== null && $context->mediaLink !== '') && is_numeric($context->mediaLink) ? 'id' : 'link');

        return $this->messagePayloadService->generate(
            $to,
            $isTemplate ? 'template' : 'custom',
            $context->templateName,
            $context->templateLanguage,
            $template,
            $context->message !== null ? (string) $context->message : null,
            $context->bodyValues,
            $context->buttonValues,
            $isTemplate ? null : 'text',
            $effectiveMedia,
            $resolvedMediaType,
            $context->fileName,
            []
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDocumentPayload(string $to, string $mediaId, string $fileName): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'document',
            'document' => [
                'id' => $mediaId,
                'filename' => $fileName,
            ],
        ];
    }
}
