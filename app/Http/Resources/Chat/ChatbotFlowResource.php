<?php

namespace App\Http\Resources\Chat;

use App\Models\Chat\ChatbotFlowVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ChatbotFlowVersion */
class ChatbotFlowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'group_id' => $this->group_id,
            'version' => $this->version,
            'status' => $this->status?->value ?? $this->status,
            'is_active' => $this->is_active,
            'definition' => $this->definition,
            'published_at' => $this->published_at?->toIso8601String(),
            'published_by' => $this->published_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
