<?php

namespace App\Traits;

use App\Exceptions\Messaging\LegacyApiValidationException;
use App\Models\Auth\ApiKey;
use App\Models\User;

trait ResolvesApiKeyTenant
{
    protected function resolveActiveApiKey(string $apiKey): ApiKey
    {
        $record = ApiKey::query()
            ->where('status', 'true')
            ->where('key', $apiKey)
            ->with(['user.userConfig', 'user.latestBalance'])
            ->first();

        if (! $record || ! $record->user) {
            throw new LegacyApiValidationException('SF0', 'Invalid API key', 401);
        }

        return $record;
    }

    protected function assertApiKeyIpAllowed(ApiKey $apiKey, string $clientIp): void
    {
        if ((string) $apiKey->ip_auth !== '1') {
            return;
        }

        $raw = $apiKey->ip_addresses;
        if (is_array($raw) && isset($raw['ip_addresses'])) {
            $allowed = array_filter(explode(',', (string) $raw['ip_addresses']));
        } elseif (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['ip_addresses'])) {
                $allowed = array_filter(explode(',', (string) $decoded['ip_addresses']));
            } else {
                $allowed = array_filter(explode(',', $raw));
            }
        } else {
            $allowed = [];
        }

        if (! in_array($clientIp, $allowed, true)) {
            throw new LegacyApiValidationException('SF7', 'IP Authentication failed', 401);
        }
    }

    protected function tenantUser(ApiKey $apiKey): User
    {
        return $apiKey->user;
    }

    protected function assertWhatsAppConfigured(User $user): void
    {
        $config = $user->userConfig;

        if (! $config?->whatsapp_phone_id || ! $config->meta_access_token) {
            throw new LegacyApiValidationException(
                'SF9',
                'WhatsApp is not configured for this account.',
                422
            );
        }
    }
}
