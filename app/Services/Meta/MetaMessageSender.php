<?php

namespace App\Services\Meta;

use Illuminate\Support\Str;

class MetaMessageSender
{
    public function __construct(
        private readonly MetaGraphClient $graph,
    ) {}

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

        $url = MetaApiUrl::messages($phoneNumberId);
        $result = $this->graph->post($url, $accessToken, $payload);
        $http = $result['status'];
        $body = $result['body'];

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
        $error = (string) ($body['error']['message'] ?? 'Message send failed');
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
    }
}
