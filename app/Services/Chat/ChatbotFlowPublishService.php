<?php

namespace App\Services\Chat;

use App\Enums\Chat\FlowStatus;
use App\Models\Chat\ChatbotFlowVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChatbotFlowPublishService
{
    public function __construct(
        private readonly ChatbotFlowDefinitionValidator $validator,
    ) {}

    public function publish(ChatbotFlowVersion $flow, int $publishedBy, ?string $note = null): ChatbotFlowVersion
    {
        $result = $this->validator->validate($flow->definition ?? []);

        if (! $result->valid) {
            throw ValidationException::withMessages([
                'definition' => $result->errors,
            ]);
        }

        return DB::transaction(function () use ($flow, $publishedBy, $note): ChatbotFlowVersion {
            if ($flow->group_id !== null) {
                ChatbotFlowVersion::query()
                    ->forUser($flow->user_id)
                    ->where('group_id', $flow->group_id)
                    ->where('is_active', true)
                    ->where('id', '!=', $flow->id)
                    ->lockForUpdate()
                    ->update(['is_active' => false]);
            }

            $flow->version = ($flow->version ?? 0) + 1;
            $flow->status = FlowStatus::Published;
            $flow->is_active = true;
            $flow->published_at = Carbon::now();
            $flow->published_by = $publishedBy;

            if ($note !== null && is_array($flow->definition)) {
                $definition = $flow->definition;
                $definition['publish_note'] = $note;
                $flow->definition = $definition;
            }

            $flow->save();

            Cache::forget("flow_v2_user_{$flow->user_id}");

            return $flow->fresh(['publisher', 'group']);
        });
    }

    public function unpublish(ChatbotFlowVersion $flow): ChatbotFlowVersion
    {
        $flow->is_active = false;
        $flow->save();

        Cache::forget("flow_v2_user_{$flow->user_id}");

        return $flow->fresh();
    }
}
