<?php

namespace App\Services\Messaging;

use App\Services\Meta\MetaApiUrl;
use App\Services\Meta\MetaGraphClient;

class WhatsAppTemplateResolver
{
    public function __construct(
        private readonly MetaGraphClient $graph,
    ) {}

    /**
     * Fetch template components from Meta Graph (same shape as WhatsAppMessageRequest::getTemplateData).
     *
     * @return array{blocks: array<string, mixed>, language: string, category: string}|array{error: string}
     */
    public function resolve(string $whatsappBusinessAccountId, string $waToken, string $templateName): array
    {
        if (! config('services.wa.api_templates') && ! env('WA_API_TEMPLATES')) {
            return ['error' => 'WA_API_TEMPLATES is not configured'];
        }

        try {
            $url = MetaApiUrl::templates($whatsappBusinessAccountId, $waToken);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }

        $result = $this->graph->get($url, $waToken);

        if ($result['status'] < 200 || $result['status'] >= 300) {
            $message = $result['body']['error']['message'] ?? 'Failed to load templates';

            return ['error' => 'Failed to load templates: '.$message];
        }

        $data = $result['body'];
        if (! isset($data['data']) || ! is_array($data['data'])) {
            return ['error' => 'Invalid templates API response'];
        }

        $foundTemplate = null;
        foreach ($data['data'] as $templateObject) {
            if (($templateObject['name'] ?? null) === $templateName) {
                $foundTemplate = $templateObject;
                break;
            }
        }

        if (! $foundTemplate) {
            return ['error' => 'no template found'];
        }

        $language = $foundTemplate['language'] ?? 'en_US';
        $header = $body = $footer = $buttons = null;

        if (isset($foundTemplate['components'])) {
            foreach ($foundTemplate['components'] as $section) {
                switch ($section['type'] ?? null) {
                    case 'HEADER':
                        $header = $section;
                        break;
                    case 'BODY':
                        $body = $section;
                        break;
                    case 'FOOTER':
                        $footer = $section;
                        break;
                    case 'BUTTONS':
                        $buttons = $section;
                        break;
                }
            }
        }

        return [
            'blocks' => array_filter([
                'header' => $header,
                'body' => $body,
                'footer' => $footer,
                'buttons' => $buttons,
            ], fn ($v) => $v !== null),
            'language' => $language,
            'category' => self::normalizeCategory((string) ($foundTemplate['category'] ?? '')),
        ];
    }

    /**
     * Map Meta template category (e.g. MARKETING) to billing key (marketing).
     */
    public static function normalizeCategory(string $metaCategory): string
    {
        $key = strtolower(trim($metaCategory));

        return match ($key) {
            'utility', 'authentication', 'service', 'marketing' => $key,
            default => 'marketing',
        };
    }
}
