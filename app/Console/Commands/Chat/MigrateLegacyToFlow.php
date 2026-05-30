<?php

namespace App\Console\Commands\Chat;

use App\Models\Chat\Chatbot;
use App\Models\Chat\ChatbotGroup;
use App\Services\Chat\ChatbotFlowService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MigrateLegacyToFlow extends Command
{
    protected $signature = 'chatbot:migrate-legacy-to-flow {userId : Tenant user id} {--group-id= : Optional chatbot group id}';

    protected $description = 'Convert legacy chatbots tree into a v2 flow definition draft';

    public function handle(ChatbotFlowService $flowService): int
    {
        $userId = (int) $this->argument('userId');
        $groupId = $this->option('group-id') ? (int) $this->option('group-id') : null;

        $query = Chatbot::query()->where('user_id', $userId);
        if ($groupId !== null) {
            $query->where('group_id', $groupId);
        }

        $chatbots = $query->orderBy('sr_no')->get();

        if ($chatbots->isEmpty()) {
            $this->warn('No legacy chatbots found for this user/group.');

            return self::FAILURE;
        }

        $groupName = 'Legacy import';
        if ($groupId !== null) {
            $group = ChatbotGroup::query()->find($groupId);
            $groupName = $group?->name ?? $groupName;
        }

        $nodes = [];
        $edges = [];

        foreach ($chatbots as $chatbot) {
            $nodeId = 'legacy-'.$chatbot->id;
            $keywords = [];
            if (! empty($chatbot->keyword)) {
                $decoded = json_decode((string) $chatbot->keyword, true);
                $keywords = is_array($decoded) ? $decoded : [(string) $chatbot->keyword];
            }

            $nodes[] = [
                'id' => $nodeId,
                'type' => empty($keywords) ? 'trigger.default' : 'trigger.keyword',
                'config' => [
                    'keywords' => $keywords,
                    'match' => 'fuzzy',
                    'legacy_chatbot_id' => $chatbot->id,
                    'reply_template' => $chatbot->reply_template ?? null,
                    'reply_text' => $chatbot->reply_text ?? null,
                ],
                'position' => ['x' => 0, 'y' => (int) ($chatbot->sr_no ?? 0) * 80],
            ];

            if (! empty($chatbot->parent_id)) {
                $edges[] = [
                    'from' => 'legacy-'.$chatbot->parent_id,
                    'to' => $nodeId,
                    'event' => 'completed',
                ];
            }
        }

        $definition = [
            'flow_id' => Str::slug($groupName.'-'.$userId),
            'version' => 1,
            'entry' => ['trigger_evaluation' => config('chatbot.trigger_policy', 'first_match')],
            'nodes' => $nodes,
            'edges' => $edges,
        ];

        $flow = $flowService->createDraft($userId, [
            'name' => $groupName,
            'group_id' => $groupId,
            'definition' => $definition,
        ]);

        $flow->legacy_group_id = $groupId;
        $flow->save();

        $this->info("Created draft flow id {$flow->id} with ".count($nodes).' nodes.');

        return self::SUCCESS;
    }
}
