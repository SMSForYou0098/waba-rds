<?php

namespace App\Http\Resources\Chat;

use App\Models\Chat\ConversationSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ConversationSession */
class ConversationSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'wa_id' => $this->wa_id,
            'display_phone_number' => $this->display_phone_number,
            'current_node_id' => $this->current_node_id,
            'awaiting_input' => $this->awaiting_input,
            'vars' => $this->vars ?? [],
            'meta' => $this->meta,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
