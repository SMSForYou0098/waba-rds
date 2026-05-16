<?php

namespace App\Services\Messaging;

/**
 * Builds Meta WhatsApp Cloud API message JSON payloads.
 * Port of payloadGenerator.js — behavior aligned with the Node implementation.
 */
class WhatsAppMessagePayloadService
{
    /**
     * @param  array<string, mixed>  $template  Normalized template shape: header, body, footer, buttons (from Graph components).
     * @param  array<int, string|int|float>  $dynamicValues  Body placeholder values in order.
     * @param  array<int, string|array<string, mixed>>  $dynamicButtonUrls  URL suffix strings and/or objects with buttonIndex/index, url|value, code.
     * @param  array<string, mixed>  $location  latitude, longitude, name, address (header LOCATION).
     * @return array<string, mixed>
     */
    public function generate(
        string $phoneNumber,
        string $requestType,
        ?string $templateName,
        string $templateLanguage,
        array $template,
        ?string $customMessage,
        array $dynamicValues = [],
        array $dynamicButtonUrls = [],
        ?string $customType = null,
        ?string $mediaLink = null,
        string $mediaType = 'id',
        ?string $fileName = null,
        array $location = [],
    ): array {
        $customType = $customType ?? 'text';

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $phoneNumber,
            'type' => $requestType === 'template' ? 'template' : $customType,
        ];

        if ($requestType === 'template') {
            $payload['template'] = [
                'name' => $templateName,
                'language' => ['code' => $templateLanguage],
                'components' => [],
            ];

            $header = $template['header'] ?? null;
            if ($header) {
                $headerComponent = $this->generateHeaderComponent($header, $mediaLink, $mediaType, $fileName, $location);
                if ($headerComponent !== null) {
                    $payload['template']['components'][] = $headerComponent;
                }
            }

            $body = $template['body'] ?? null;
            if ($body && count($dynamicValues) > 0) {
                $payload['template']['components'][] = [
                    'type' => 'body',
                    'parameters' => array_map(
                        fn ($value) => ['type' => 'text', 'text' => (string) $value],
                        $dynamicValues
                    ),
                ];
            }

            $buttonsSection = $template['buttons'] ?? null;
            $innerButtons = is_array($buttonsSection) ? ($buttonsSection['buttons'] ?? null) : null;
            if (is_array($innerButtons) && count($innerButtons) > 0) {
                $buttonComponents = $this->generateButtonComponents($innerButtons, $dynamicButtonUrls);
                foreach ($buttonComponents as $component) {
                    $payload['template']['components'][] = $component;
                }
            }
        } else {
            if ($customType === 'text') {
                $payload[$customType] = [
                    'preview_url' => false,
                    'body' => $customMessage ?? '',
                ];
            } else {
                $payload[$customType] = [
                    'id' => $customMessage ?? '',
                ];
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $location
     * @return array<string, mixed>|null
     */
    private function generateHeaderComponent(
        array $header,
        ?string $mediaLink,
        string $mediaType,
        ?string $fileName,
        array $location = [],
    ): ?array {
        if (($header['format'] ?? null) === 'TEXT') {
            return null;
        }

        $headerComponent = [
            'type' => 'header',
            'parameters' => [],
        ];

        $headerMediaType = $this->getMediaType($header['format'] ?? '');
        $mediaContent = $this->generateMediaContent(
            $header['format'] ?? '',
            $mediaLink,
            $mediaType,
            $fileName,
            $location
        );

        if ($mediaContent !== null) {
            $headerComponent['parameters'][] = array_merge(
                ['type' => $headerMediaType],
                $mediaContent
            );
        }

        return count($headerComponent['parameters']) > 0 ? $headerComponent : null;
    }

    private function getMediaType(string $format): string
    {
        return match ($format) {
            'IMAGE' => 'image',
            'VIDEO' => 'video',
            'DOCUMENT' => 'document',
            'LOCATION' => 'location',
            default => 'text',
        };
    }

    /**
     * @param  array<string, mixed>  $location
     * @return array<string, mixed>|null
     */
    private function generateMediaContent(
        string $format,
        ?string $mediaLink,
        string $mediaType,
        ?string $fileName,
        array $location = [],
    ): ?array {
        if ($format !== 'LOCATION' && ! $mediaLink) {
            return null;
        }

        return match ($format) {
            'IMAGE' => ['image' => [$mediaType => $mediaLink]],
            'VIDEO' => ['video' => [$mediaType => $mediaLink]],
            'DOCUMENT' => ['document' => [
                $mediaType => $mediaLink,
                'filename' => $fileName,
            ]],
            'LOCATION' => [
                'location' => [
                    'latitude' => $location['latitude'] ?? null,
                    'longitude' => $location['longitude'] ?? null,
                    'name' => $location['name'] ?? null,
                    'address' => $location['address'] ?? null,
                ],
            ],
            default => null,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $buttons
     * @param  array<int, string|array<string, mixed>>  $dynamicButtonUrls
     * @return array<int, array<string, mixed>>
     */
    private function generateButtonComponents(array $buttons, array $dynamicButtonUrls = []): array
    {
        $buttonComponents = [];

        foreach ($buttons as $index => $button) {
            if (! $this->needsParameters($button)) {
                continue;
            }

            $buttonComponent = [
                'type' => 'button',
                'sub_type' => $this->getButtonSubType($button['type'] ?? ''),
                'index' => (string) $index,
                'parameters' => [],
            ];

            $parameters = $this->generateButtonParameters($button, $dynamicButtonUrls, $index);
            if (count($parameters) > 0) {
                $buttonComponent['parameters'] = $parameters;
                $buttonComponents[] = $buttonComponent;
            }
        }

        return $buttonComponents;
    }

    private function getButtonSubType(string $buttonType): string
    {
        return match ($buttonType) {
            'QUICK_REPLY' => 'quick_reply',
            'URL' => 'url',
            'PHONE_NUMBER' => 'phone_number',
            'COPY_CODE' => 'copy_code',
            'FLOW' => 'flow',
            'CATALOG' => 'catalog',
            default => 'quick_reply',
        };
    }

    /**
     * @param  array<string, mixed>  $button
     * @param  array<int, string|array<string, mixed>>  $dynamicButtonUrls
     * @return array<int, array<string, mixed>>
     */
    private function generateButtonParameters(array $button, array $dynamicButtonUrls, int $buttonIndex): array
    {
        return match ($button['type'] ?? null) {
            'URL' => $this->urlButtonParameters($button, $dynamicButtonUrls, $buttonIndex),
            'COPY_CODE' => $this->copyCodeButtonParameters($dynamicButtonUrls, $buttonIndex),
            'FLOW' => [[
                'type' => 'action',
                'action' => [
                    'flow_token' => (string) $this->jsOr(
                        $button['flow_token'] ?? null,
                        'flow_token_'.(string) (int) (microtime(true) * 1000)
                    ),
                    'flow_action_data' => [
                        'screen' => (string) $this->jsOr($button['navigate_screen'] ?? null, 'SIGN_UP'),
                        'data' => $this->flowActionData($button),
                    ],
                ],
            ]],
            'QUICK_REPLY', 'PHONE_NUMBER' => [],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $button
     * @param  array<int, string|array<string, mixed>>  $dynamicButtonUrls
     * @return array<int, array<string, string>>
     */
    private function urlButtonParameters(array $button, array $dynamicButtonUrls, int $buttonIndex): array
    {
        $url = $button['url'] ?? '';
        if (! is_string($url) || ! str_contains($url, '{{')) {
            return [];
        }

        $dynamicValue = null;

        if (is_array($dynamicButtonUrls)) {
            if (isset($dynamicButtonUrls[$buttonIndex]) && is_string($dynamicButtonUrls[$buttonIndex])) {
                $dynamicValue = $dynamicButtonUrls[$buttonIndex];
            } elseif (isset($dynamicButtonUrls[$buttonIndex]) && is_array($dynamicButtonUrls[$buttonIndex])) {
                foreach ($dynamicButtonUrls as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    if (($item['buttonIndex'] ?? null) === $buttonIndex || ($item['index'] ?? null) === $buttonIndex) {
                        $dynamicValue = $item['url'] ?? $item['value'] ?? null;
                        break;
                    }
                }
            }
        }

        if ($dynamicValue === null) {
            return [];
        }

        $urlValues = is_array($dynamicValue) ? $dynamicValue : [$dynamicValue];

        return array_map(
            fn ($value) => ['type' => 'text', 'text' => (string) $value],
            $urlValues
        );
    }

    /**
     * @param  array<int, string|array<string, mixed>>  $dynamicButtonUrls
     * @return array<int, array<string, string>>
     */
    private function copyCodeButtonParameters(array $dynamicButtonUrls, int $buttonIndex): array
    {
        foreach ($dynamicButtonUrls as $item) {
            if (! is_array($item)) {
                continue;
            }
            $matchesIndex = ($item['buttonIndex'] ?? null) === $buttonIndex || ($item['index'] ?? null) === $buttonIndex;
            if ($matchesIndex && isset($item['code'])) {
                return [['type' => 'text', 'text' => (string) $item['code']]];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $button
     */
    private function needsParameters(array $button): bool
    {
        return match ($button['type'] ?? null) {
            'URL' => isset($button['url']) && is_string($button['url']) && str_contains($button['url'], '{{'),
            'COPY_CODE', 'FLOW' => true,
            'QUICK_REPLY', 'PHONE_NUMBER' => false,
            default => false,
        };
    }

    /**
     * Mirrors JS: button.flow_data || {}
     *
     * @param  array<string, mixed>  $button
     */
    private function flowActionData(array $button): mixed
    {
        $fd = $button['flow_data'] ?? null;

        if ($fd === null || $fd === false || $fd === '' || $fd === 0) {
            return new \stdClass;
        }

        return $fd;
    }

    /**
     * Mirrors JavaScript's `value || default` for null, false, empty string, and 0.
     */
    private function jsOr(mixed $value, mixed $default): mixed
    {
        if ($value === null || $value === false || $value === '' || $value === 0) {
            return $default;
        }

        return $value;
    }
}
