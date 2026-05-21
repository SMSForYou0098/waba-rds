<?php

namespace App\Http\Resources\Messaging;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin array{status: bool, error: string, error_code?: string, error_type?: string, invalid_params?: string}
 */
class LegacyApiErrorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => false,
            'error' => $this->resource['error'] ?? 'Request failed',
            'error_code' => $this->resource['error_code'] ?? null,
            'error_type' => $this->resource['error_type'] ?? null,
            'invalid_params' => $this->resource['invalid_params'] ?? null,
        ];
    }
}
