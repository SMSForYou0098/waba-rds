<?php

namespace App\Services\Meta;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Str;
use Throwable;

class MetaMessageSender
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, wamid: ?string, code: ?string, error: ?string, http: ?int, retryable: bool}
     */
    public function send(string $phoneNumberId, string $accessToken, array $payload): array
    {
        if (config('services.meta.dry_run')) {
            usleep(200000);

            return [
                'ok' => true,
                'wamid' => 'DRYRUN-'.Str::uuid()->toString(),
                'code' => null,
                'error' => null,
                'http' => 200,
                'retryable' => false,
            ];
        }

        $messagesApi = env('WA_API_MESSAGES');
        if (is_string($messagesApi) && $messagesApi !== '') {
            $url = str_replace(':whatsapp_phone_id:', $phoneNumberId, $messagesApi);
        } else {
            $version = (string) config('services.meta.api_version', 'v25.0');
            $url = "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";
        }

        try {
            $client = new Client([
                'connect_timeout' => 5,
                'timeout' => 15,
                'http_errors' => false,
            ]);

            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$accessToken,
                    'Accept' => 'application/json',
                ],
                'body' => json_encode($payload),
            ]);

            $http = $response->getStatusCode();
            $body = json_decode((string) $response->getBody(), true) ?? [];

            if ($http >= 200 && $http < 300) {
                return [
                    'ok' => true,
                    'wamid' => $body['messages'][0]['id'] ?? null,
                    'code' => null,
                    'error' => null,
                    'http' => $http,
                    'retryable' => false,
                ];
            }

            $code = (string) ($body['error']['code'] ?? '');
            $error = (string) ($body['error']['message'] ?? (string) $response->getBody());
            $retryable = in_array($http, [429, 500, 502, 503, 504], true)
                || in_array($code, ['130429', '131056', '368'], true);

            return [
                'ok' => false,
                'wamid' => null,
                'code' => $code !== '' ? $code : null,
                'error' => $error,
                'http' => $http,
                'retryable' => $retryable,
            ];
        } catch (GuzzleException $e) {
            return [
                'ok' => false,
                'wamid' => null,
                'code' => 'NETWORK',
                'error' => $e->getMessage(),
                'http' => null,
                'retryable' => true,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'wamid' => null,
                'code' => 'EXCEPTION',
                'error' => $e->getMessage(),
                'http' => null,
                'retryable' => false,
            ];
        }
    }
}
