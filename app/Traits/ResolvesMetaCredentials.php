<?php

namespace App\Traits;

use App\Services\Meta\MetaApiUrl;
use Illuminate\Support\Facades\Log;

trait ResolvesMetaCredentials
{
    /**
     * Resolve the authenticated tenant's WhatsApp credentials from DB.
     * Aborts with 403 if config is missing or incomplete.
     *
     * @return array{phone_id: string, waba_id: string, token: string}
     */
    protected function resolveCredentials(): array
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $user->loadMissing('userConfig');
        $config = $user->userConfig;

        abort_if(
            ! $config || ! $config->meta_access_token || ! $config->whatsapp_phone_id || ! $config->whatsapp_business_account_id,
            403,
            'WhatsApp credentials are not configured for this account.'
        );

        return [
            'phone_id' => (string) $config->whatsapp_phone_id,
            'waba_id'  => (string) $config->whatsapp_business_account_id,
            'token'    => (string) $config->meta_access_token,
        ];
    }

    /**
     * Build a Meta API URL from an env key with {{placeholder}} substitution.
     *
     * @param  string               $envKey       e.g. 'WA_API_TEMPLATES'
     * @param  array<string,string> $replacements  e.g. ['{{waba_id}}' => '123']
     */
    protected function waUrl(string $envKey, array $replacements = []): string
    {
        try {
            return MetaApiUrl::build($envKey, $replacements);
        } catch (\RuntimeException $e) {
            abort(500, $e->getMessage());
        }
    }

    /**
     * Standard Meta API request headers (no Content-Type — Http facade sets it per request).
     *
     * @return array<string,string>
     */
    protected function metaHeaders(string $token): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Strip internal Meta debug fields from an error response before returning to client.
     *
     * @param  array<string,mixed> $body
     * @return array<string,mixed>
     */
    protected function sanitizeMetaResponse(array $body): array
    {
        if (isset($body['error']) && is_array($body['error'])) {
            unset($body['error']['fbtrace_id'], $body['error']['error_data']);
        }

        return $body;
    }

    /**
     * Log an outbound Meta API call (method, endpoint without token, user_id, status).
     */
    protected function logMetaCall(string $method, string $url, int $status): void
    {
        // Strip access_token from logged URL to avoid leaking it in logs
        $safeUrl = preg_replace('/access_token=[^&]+/', 'access_token=***', $url);

        Log::channel('stack')->info('Meta API proxy call', [
            'method'  => strtoupper($method),
            'url'     => $safeUrl,
            'status'  => $status,
            'user_id' => auth()->id(),
        ]);
    }
}
