<?php

namespace App\Services\Messaging\LegacyApi;

use App\Exceptions\Messaging\LegacyApiValidationException;
use App\Services\Messaging\WhatsAppTemplateResolver;
use Illuminate\Http\UploadedFile;

class LegacyApiTemplateService
{
    public function __construct(
        private readonly WhatsAppTemplateResolver $templateResolver,
    ) {}

    /**
     * @return array{
     *     blocks: array<string, mixed>,
     *     language: string,
     *     category: string
     * }
     */
    public function resolve(
        string $wabaId,
        string $waToken,
        string $templateName,
    ): array {
        $resolved = $this->templateResolver->resolve($wabaId, $waToken, $templateName);

        if (isset($resolved['error'])) {
            throw new LegacyApiValidationException('SF4', (string) $resolved['error'], 400);
        }

        return [
            'blocks' => $resolved['blocks'],
            'language' => (string) ($resolved['language'] ?? 'en_US'),
            'category' => (string) ($resolved['category'] ?? 'marketing'),
        ];
    }

    /**
     * @param  array<string, mixed>  $templateBlocks
     * @param  list<string>  $bodyValues
     * @param  list<string>  $buttonValues
     */
    public function validateTemplateInputs(
        array $templateBlocks,
        array $bodyValues,
        array $buttonValues,
        ?string $mediaLink,
        ?string $mediaId,
        ?UploadedFile $mediaFile,
    ): void {
        $headerType = $templateBlocks['header']['format'] ?? null;
        $body = (string) ($templateBlocks['body']['text'] ?? '');

        preg_match_all('/{{\d+}}/', $body, $matches);
        $requiredBodyParams = count($matches[0]);

        $templateButtons = $templateBlocks['buttons']['buttons'][0] ?? null;
        $buttonType = $templateButtons['type'] ?? null;

        if ($headerType && $headerType !== 'TEXT' && ! $mediaLink && ! $mediaId && ! $mediaFile) {
            throw new LegacyApiValidationException(
                'SF4',
                'Media url or file is required for this template',
                400
            );
        }

        if ($requiredBodyParams > 0 && $requiredBodyParams !== count($bodyValues)) {
            throw new LegacyApiValidationException(
                'SF5',
                "Number of dynamic values doesn't match template requirements. Expected: {$requiredBodyParams}, Provided: ".count($bodyValues),
                400
            );
        }

        if ($requiredBodyParams === 0 && count($bodyValues) > 0) {
            throw new LegacyApiValidationException(
                'SF5',
                "This template doesn't require any dynamic values, but ".count($bodyValues).' value(s) were provided.',
                400
            );
        }

        if ($buttonType === 'URL' && isset($templateButtons['example']) && count($buttonValues) === 0) {
            throw new LegacyApiValidationException(
                'SF6',
                'Button parameter is required for URL button templates',
                400
            );
        }
    }

    /**
     * @return list<string>
     */
    public function parseCommaList(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_filter(explode(',', $value), static fn ($v) => $v !== ''));
    }
}
