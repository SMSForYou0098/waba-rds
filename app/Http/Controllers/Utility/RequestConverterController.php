<?php

namespace App\Http\Controllers\Utility;

use App\Http\Controllers\Controller;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\Response as HttpClientResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class RequestConverterController extends Controller
{
    /**
     * GET `url`, `payload`, optional `headers` — forwards a JSON POST and returns
     * the downstream response as-is (status, body, Content-Type). `headers` uses
     * the same JSON rules as `payload`. JSON 502 is returned only if the outbound
     * request cannot be completed (e.g. connection error).
     */
    public function convert(Request $request): Response|JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'payload' => ['required', 'string', 'max:65535'],
            'headers' => ['nullable', 'string', 'max:8192'],
        ]);

        $targetUrl = $this->normalizeTargetUrl($validated['url']);
        $body = $this->decodeJsonPayload($validated['payload']);
        $extraHeaders = isset($validated['headers']) && $validated['headers'] !== ''
            ? $this->decodeJsonHeadersLikePayload($validated['headers'])
            : [];

        try {
            $upstream = $this->forwardJsonPost($targetUrl, $body, $extraHeaders);
        } catch (GuzzleException $e) {
            return response()->json([
                'error' => 'connection_failed',
                'message' => $e->getMessage(),
            ], 502);
        }

        $laravelResponse = response(
            $upstream->body(),
            $upstream->status() ?: 502
        );

        $contentType = $upstream->header('Content-Type');
        if ($contentType !== '') {
            $laravelResponse->headers->set('Content-Type', $contentType);
        }

        return $laravelResponse;
    }

    /**
     * @param  array<string, string>  $extraHeaders
     */
    private function forwardJsonPost(string $targetUrl, array $body, array $extraHeaders = []): HttpClientResponse
    {
        $defaultHeaders = ['Accept' => 'application/json'];
        $headers = array_merge($defaultHeaders, $extraHeaders);

        $client = new Client([
            'timeout' => 60.0,
            'connect_timeout' => 15.0,
            'http_errors' => false,
            'headers' => $headers,
        ]);

        $psrResponse = $client->post($targetUrl, [
            'json' => $body,
        ]);

        return new HttpClientResponse($psrResponse);
    }

    private function normalizeTargetUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw ValidationException::withMessages(['url' => 'URL is empty.']);
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages(['url' => 'Invalid URL.']);
        }

        return $url;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonPayload(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw ValidationException::withMessages(['payload' => 'Payload is empty.']);
        }

        return $this->decodeJsonQueryObject($raw, 'payload');
    }

    /**
     * Same decoding as {@see decodeJsonPayload}; values are turned into strings for HTTP headers.
     *
     * @return array<string, string>
     */
    private function decodeJsonHeadersLikePayload(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = $this->decodeJsonQueryObject($raw, 'headers');

        return $this->stringifyHeaderValues($decoded);
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, string>
     */
    private function stringifyHeaderValues(array $decoded): array
    {
        $headers = [];
        foreach ($decoded as $name => $value) {
            $name = (string) $name;
            if ($name === '') {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                $headers[$name] = json_encode($value);
            } elseif (is_bool($value)) {
                $headers[$name] = $value ? '1' : '0';
            } elseif ($value === null) {
                $headers[$name] = '';
            } else {
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonQueryObject(string $raw, string $field): array
    {
        $first = json_decode($raw, true);
        $firstError = json_last_error();

        if ($firstError === JSON_ERROR_NONE && is_string($first)) {
            $second = json_decode($first, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($second)) {
                return $second;
            }
        }

        if ($firstError === JSON_ERROR_NONE && is_array($first)) {
            return $first;
        }

        throw ValidationException::withMessages([
            $field => 'Invalid JSON for '.$field.': '.json_last_error_msg(),
        ]);
    }
}
