<?php

namespace App\Services\Chat;

use App\Enums\Chat\FlowStatus;
use App\Models\Chat\ChatbotFlowVersion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChatbotFlowService
{
    /**
     * @param  array{group_id?: int|null, status?: string|null, search?: string|null, page?: int, per_page?: int}  $filters
     */
    public function listForUser(int $userId, array $filters = []): LengthAwarePaginator
    {
        $query = ChatbotFlowVersion::query()
            ->forUser($userId)
            ->orderByDesc('updated_at');

        if (! empty($filters['group_id'])) {
            $query->where('group_id', (int) $filters['group_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', $search)
                    ->orWhere('slug', 'like', $search);
            });
        }

        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 15)));

        return $query->paginate($perPage);
    }

    /**
     * @param  array{name: string, group_id?: int|null, definition?: array<string, mixed>|null}  $data
     */
    public function createDraft(int $userId, array $data): ChatbotFlowVersion
    {
        $name = $data['name'];
        $slug = Str::slug($name);

        return ChatbotFlowVersion::query()->create([
            'user_id' => $userId,
            'group_id' => $data['group_id'] ?? null,
            'name' => $name,
            'slug' => $slug,
            'version' => 1,
            'status' => FlowStatus::Draft,
            'definition' => $data['definition'] ?? $this->defaultDefinition($slug),
            'is_active' => false,
        ]);
    }

    public function findOrFail(int $id): ChatbotFlowVersion
    {
        return ChatbotFlowVersion::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function saveDefinition(ChatbotFlowVersion $flow, array $definition): ChatbotFlowVersion
    {
        // if (! $flow->isDraft()) {
        //     throw ValidationException::withMessages([
        //         'definition' => ['Only draft flows can be updated. Duplicate or create a new draft.'],
        //     ]);
        // }

        $flow->definition = $definition;
        $flow->save();

        return $flow->fresh();
    }

    /**
     * @param  array{name?: string, group_id?: int|null, slug?: string|null}  $fields
     */
    public function updateMeta(ChatbotFlowVersion $flow, array $fields): ChatbotFlowVersion
    {
        if (isset($fields['name'])) {
            $flow->name = $fields['name'];
            if (! isset($fields['slug'])) {
                $flow->slug = Str::slug($fields['name']);
            }
        }

        if (array_key_exists('group_id', $fields)) {
            $flow->group_id = $fields['group_id'];
        }

        if (isset($fields['slug'])) {
            $flow->slug = $fields['slug'];
        }

        $flow->save();

        return $flow->fresh();
    }

    public function deleteDraft(ChatbotFlowVersion $flow): void
    {
        if ($flow->is_active) {
            throw ValidationException::withMessages([
                'flow' => ['Cannot delete an active published flow.'],
            ]);
        }

        $flow->delete();
    }

    public function duplicate(ChatbotFlowVersion $flow, int $userId): ChatbotFlowVersion
    {
        return ChatbotFlowVersion::query()->create([
            'user_id' => $userId,
            'group_id' => $flow->group_id,
            'name' => $flow->name.' (copy)',
            'slug' => Str::slug($flow->name.'-copy-'.Str::random(4)),
            'version' => 1,
            'status' => FlowStatus::Draft,
            'definition' => $flow->definition,
            'is_active' => false,
        ]);
    }

    public function activeForGroup(int $userId, int $groupId): ?ChatbotFlowVersion
    {
        return ChatbotFlowVersion::query()
            ->forUser($userId)
            ->inGroup($groupId)
            ->active()
            ->published()
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultDefinition(string $flowId): array
    {
        return [
            'flow_id' => $flowId,
            'version' => 1,
            'entry' => ['trigger_evaluation' => config('chatbot.trigger_policy', 'first_match')],
            'nodes' => [
                [
                    'id' => 'trg-default',
                    'type' => 'trigger.keyword',
                    'config' => ['keywords' => [], 'match' => 'fuzzy'],
                    'position' => ['x' => 0, 'y' => 0],
                ],
            ],
            'edges' => [],
        ];
    }
}
