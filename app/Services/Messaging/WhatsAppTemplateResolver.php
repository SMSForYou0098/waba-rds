<?php

namespace App\Services\Messaging;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class WhatsAppTemplateResolver
{
    /**
     * Fetch template components from Meta Graph (same shape as WhatsAppMessageRequest::getTemplateData).
     *
     * @return array{blocks: array<string, mixed>, language: string}|array{error: string}
     */
    public function resolve(string $whatsappBusinessAccountId, string $waToken, string $templateName): array
    {
        $templatesUrl = config('services.wa.api_templates');
        if (! $templatesUrl) {
            return ['error' => 'WA_API_TEMPLATES is not configured'];
        }

        $templatesApi = str_replace(':whatsapp_business_account_id:', $whatsappBusinessAccountId, $templatesUrl);
        $templatesApi = str_replace(':waToken:', $waToken, $templatesApi);

        $client = new Client;

        try {
            $response = $client->get($templatesApi, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$waToken,
                ],
            ]);
        } catch (GuzzleException $e) {
            return ['error' => 'Failed to load templates: '.$e->getMessage()];
        }

        $data = json_decode($response->getBody()->getContents(), true);
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
        ];
    }
}
