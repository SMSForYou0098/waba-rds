<?php

namespace App\Http\Resources\Messaging;

use App\Data\Messaging\LegacyApiBulkSendResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LegacyApiBulkSendResult */
class LegacyApiBulkSendResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => true,
            'success_count' => $this->successCount,
            'failure_count' => $this->failureCount,
            'error_summary' => $this->failures,
            'message' => 'Message sending process completed',
        ];
    }
}
