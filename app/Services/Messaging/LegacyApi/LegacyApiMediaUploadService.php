<?php

namespace App\Services\Messaging\LegacyApi;

use App\Exceptions\Messaging\LegacyApiValidationException;
use App\Services\Meta\MetaApiUrl;
use App\Services\Meta\MetaGraphClient;
use Illuminate\Http\UploadedFile;

class LegacyApiMediaUploadService
{
    private const MAX_BYTES = 100 * 1024 * 1024;

    public function __construct(
        private readonly MetaGraphClient $graph,
    ) {}

    /**
     * @return array{id: string, filename: string}
     */
    public function uploadForTemplateHeader(string $phoneId, string $waToken, UploadedFile $mediaFile): array
    {
        return $this->upload($phoneId, $waToken, $mediaFile);
    }

    /**
     * @return array{id: string, filename: string}
     */
    public function uploadDocument(string $phoneId, string $waToken, UploadedFile $mediaFile): array
    {
        return $this->upload($phoneId, $waToken, $mediaFile);
    }

    /**
     * @return array{id: string, filename: string}
     */
    private function upload(string $phoneId, string $waToken, UploadedFile $mediaFile): array
    {
        if ($mediaFile->getSize() > self::MAX_BYTES) {
            throw new LegacyApiValidationException('VAL005', 'PDF size must be less than 100MB.', 415);
        }

        $url = MetaApiUrl::media($phoneId);

        $formData = [
            ['name' => 'type', 'contents' => 'image/jpeg'],
            ['name' => 'messaging_product', 'contents' => 'whatsapp'],
            [
                'name' => 'file',
                'contents' => fopen($mediaFile->path(), 'r'),
                'filename' => $mediaFile->getClientOriginalName(),
            ],
        ];

        $uploadResult = $this->graph->postMultipartParts($url, $waToken, $formData);
        $statusCode = $uploadResult['status'];
        $uploadBody = $uploadResult['body'];

        if ($statusCode >= 200 && $statusCode < 300 && isset($uploadBody['id'])) {
            return [
                'id' => (string) $uploadBody['id'],
                'filename' => $mediaFile->getClientOriginalName(),
            ];
        }

        throw new LegacyApiValidationException(
            'VAL005',
            'Media upload failed: '.json_encode($uploadBody),
            $statusCode >= 400 ? $statusCode : 400
        );
    }
}
