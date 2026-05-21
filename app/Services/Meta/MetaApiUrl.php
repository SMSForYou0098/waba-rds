<?php

namespace App\Services\Meta;

use RuntimeException;

class MetaApiUrl
{
    /**
     * Build a Meta API URL from an env template with {{placeholder}} substitution.
     *
     * @param  array<string, string>  $replacements
     */
    public static function build(string $envKey, array $replacements = []): string
    {
        $template = (string) env($envKey, '');

        if ($template === '') {
            throw new RuntimeException("Environment variable {$envKey} is not configured.");
        }

        if ($replacements === []) {
            return $template;
        }

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    public static function messages(string $phoneNumberId): string
    {
        $template = (string) env('WA_API_MESSAGES', '');

        if ($template !== '') {
            $url = self::build('WA_API_MESSAGES', [
                '{{whatsapp_phone_id}}' => $phoneNumberId,
            ]);

            if (str_contains($url, '{{whatsapp_phone_id}}')) {
                return self::defaultMessagesUrl($phoneNumberId);
            }

            return $url;
        }

        return self::defaultMessagesUrl($phoneNumberId);
    }

    public static function media(string $phoneNumberId): string
    {
        $template = (string) env('WA_API_MEDIA', '');

        if ($template !== '') {
            $url = self::build('WA_API_MEDIA', [
                '{{whatsapp_phone_id}}' => $phoneNumberId,
            ]);

            if (str_contains($url, '{{whatsapp_phone_id}}')) {
                return str_replace('/messages', '/media', self::defaultMessagesUrl($phoneNumberId));
            }

            return $url;
        }

        return str_replace('/messages', '/media', self::messages($phoneNumberId));
    }

    public static function templates(string $wabaId, string $token): string
    {
        return self::build('WA_API_TEMPLATES', [
            '{{whatsapp_business_account_id}}' => $wabaId,
            '{{wa_token}}' => $token,
        ]);
    }

    private static function defaultMessagesUrl(string $phoneNumberId): string
    {
        $version = (string) config('services.meta.api_version', 'v25.0');

        return "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";
    }

    public static function analytics(string $wapId, int $startUnix, int $endUnix, string $token): string
    {
        return self::build('WA_API_ANALYTICS', [
            '{{wapid}}' => (string) $wapId,
            '{{start_unix}}' => (string) $startUnix,
            '{{realtime_unix}}' => (string) $endUnix,
            '{{wa_token}}' => $token,
        ]);
    }
}
