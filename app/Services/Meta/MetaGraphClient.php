<?php

namespace App\Services\Meta;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class MetaGraphClient
{
    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client([
            'connect_timeout' => 5,
            'timeout'         => 30,
            'http_errors'     => false,
        ]);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function get(string $url, string $accessToken): array
    {
        return $this->request('GET', $url, $accessToken);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    public function post(string $url, string $accessToken, array $payload = []): array
    {
        return $this->request('POST', $url, $accessToken, $payload);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function delete(string $url, string $accessToken): array
    {
        return $this->request('DELETE', $url, $accessToken);
    }

    /**
     * OAuth token exchange — no Bearer token; uses query params.
     *
     * @param  array<string, string>  $query
     * @return array{status: int, body: array<string, mixed>}
     */
    public function getWithQuery(string $url, array $query): array
    {
        try {
            $response = $this->client->get($url, [
                RequestOptions::QUERY => $query,
            ]);

            return $this->parseResponse($response);
        } catch (GuzzleException|Throwable $e) {
            return [
                'status' => 502,
                'body'   => ['error' => ['message' => $e->getMessage()]],
            ];
        }
    }

    /**
     * Multipart POST with custom field order (for legacy upload flows).
     *
     * @param  array<int, array{name: string, contents: mixed, filename?: string}>  $parts
     * @return array{status: int, body: array<string, mixed>}
     */
    public function postMultipartParts(string $url, string $accessToken, array $parts): array
    {
        try {
            $response = $this->client->post($url, [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer '.$accessToken,
                ],
                RequestOptions::MULTIPART => $parts,
            ]);

            return $this->parseResponse($response);
        } catch (GuzzleException|Throwable $e) {
            return [
                'status' => 502,
                'body'   => ['error' => ['message' => $e->getMessage()]],
            ];
        }
    }

    /**
     * Multipart media upload to Meta.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function postMultipart(
        string $url,
        string $accessToken,
        string $fileContents,
        string $filename,
        string $mimeType
    ): array {
        try {
            $response = $this->client->post($url, [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer '.$accessToken,
                ],
                RequestOptions::MULTIPART => [
                    [
                        'name'     => 'messaging_product',
                        'contents' => 'whatsapp',
                    ],
                    [
                        'name'     => 'type',
                        'contents' => $mimeType,
                    ],
                    [
                        'name'     => 'file',
                        'contents' => $fileContents,
                        'filename' => $filename,
                    ],
                ],
            ]);

            return $this->parseResponse($response);
        } catch (GuzzleException|Throwable $e) {
            return [
                'status' => 502,
                'body'   => ['error' => ['message' => $e->getMessage()]],
            ];
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    private function request(string $method, string $url, string $accessToken, ?array $payload = null): array
    {
        try {
            $options = [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer '.$accessToken,
                    'Accept'        => 'application/json',
                ],
            ];

            if ($payload !== null && $method !== 'GET' && $method !== 'DELETE') {
                $options[RequestOptions::HEADERS]['Content-Type'] = 'application/json';
                $options[RequestOptions::JSON] = $payload;
            }

            $response = $this->client->request($method, $url, $options);

            return $this->parseResponse($response);
        } catch (GuzzleException|Throwable $e) {
            return [
                'status' => 502,
                'body'   => ['error' => ['message' => $e->getMessage()]],
            ];
        }
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function parseResponse(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return [
            'status' => $response->getStatusCode(),
            'body'   => is_array($decoded) ? $decoded : [],
        ];
    }
}
