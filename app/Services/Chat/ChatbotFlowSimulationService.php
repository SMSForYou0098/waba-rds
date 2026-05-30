<?php

namespace App\Services\Chat;

use App\Data\Chat\SimulationStepResult;
use App\Enums\Chat\NodeType;
use App\Models\Chat\ChatbotFlowVersion;
use App\Models\Chat\ConversationSession;
use Illuminate\Support\Carbon;

class ChatbotFlowSimulationService
{
    use ChatbotFlowIncomingCapture;
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function indexNodes(array $definition): array
    {
        $byId = [];
        foreach ($definition['nodes'] ?? [] as $node) {
            if (is_array($node) && isset($node['id'])) {
                $byId[$node['id']] = $node;
            }
        }

        return $byId;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function indexEdgesByFrom(array $definition): array
    {
        $byFrom = [];
        foreach ($definition['edges'] ?? [] as $edge) {
            if (! is_array($edge) || empty($edge['from'])) {
                continue;
            }
            $byFrom[$edge['from']][] = $edge;
        }

        return $byFrom;
    }

    public function step(
        ChatbotFlowVersion $flow,
        int $userId,
        string $message,
        ?string $waId = null,
        bool $useDraft = false,
        bool $reset = false,
    ): SimulationStepResult {
        if (! $useDraft && $flow->isDraft()) {
            $published = ChatbotFlowVersion::query()
                ->forUser($userId)
                ->where('slug', $flow->slug)
                ->published()
                ->orderByDesc('version')
                ->first();

            if ($published) {
                $flow = $published;
            }
        }

        $waId = $waId ?: (string) config('chatbot.simulation.default_wa_id', '919800000000');
        $definition = $flow->definition ?? [];
        $nodesById = $this->indexNodes($definition);
        $edgesByFrom = $this->indexEdgesByFrom($definition);

        if ($reset) {
            ConversationSession::query()
                ->forCustomer($userId, $waId)
                ->delete();
        }

        $session = ConversationSession::query()->firstOrNew([
            'user_id' => $userId,
            'wa_id' => $waId,
        ]);

        if (! $session->exists) {
            $session->flow_version_id = $flow->id;
            $session->vars = [];
            $session->meta = [];
            $session->awaiting_input = false;
            $session->current_node_id = null;
        } elseif ($session->flow_version_id !== $flow->id) {
            $session->flow_version_id = $flow->id;
            $session->current_node_id = null;
            $session->awaiting_input = false;
            $session->vars = [];
            $session->meta = [];
        }

        $trace = [];
        $outbound = [];
        $maxSteps = (int) config('chatbot.simulation.max_steps', 50);
        $steps = 0;

        if (
            $session->current_node_id === null
            && $this->getWaitingForReplyNodeId($session) !== null
            && $this->tryHandleIncomingReply($session, $message, $nodesById, $edgesByFrom, $trace)
        ) {
            // Plain-text reply routed (capture + button_reply edge).
        } elseif ($session->current_node_id === null) {
            $matched = $this->matchTrigger($message, $nodesById, $definition);
            if ($matched !== null) {
                $trace[] = ['node_id' => $matched, 'event' => 'matched'];
                $session->current_node_id = $matched;
            }
        } elseif ($session->awaiting_input) {
            $current = $nodesById[$session->current_node_id] ?? null;
            if ($current && ($current['type'] ?? '') === NodeType::LogicAsk->value) {
                $varName = $current['config']['var'] ?? 'input';
                $vars = $session->vars ?? [];
                $vars[$varName] = $message;
                $session->vars = $vars;
                $session->awaiting_input = false;
                $trace[] = ['node_id' => $session->current_node_id, 'event' => 'user_input'];
                $next = $this->findEdgeTarget($edgesByFrom, $session->current_node_id, 'user_input');
                if ($next !== null) {
                    $session->current_node_id = $next;
                }
            }
        }

        while ($session->current_node_id !== null && $steps < $maxSteps) {
            $steps++;
            $node = $nodesById[$session->current_node_id] ?? null;
            if ($node === null) {
                $session->current_node_id = null;
                break;
            }

            $type = $node['type'] ?? '';
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];

            if ($type === NodeType::LogicAsk->value) {
                $outbound[] = [
                    'type' => 'text',
                    'body' => $config['prompt_text'] ?? 'Please reply',
                ];
                $session->awaiting_input = true;
                $trace[] = ['node_id' => $session->current_node_id, 'event' => 'completed'];
                break;
            }

            if (in_array($type, [
                NodeType::MessageText->value,
                NodeType::MessageTemplate->value,
                NodeType::MessageInteractiveButtons->value,
                NodeType::MessageListPreset->value,
            ], true)) {
                $nodeId = $session->current_node_id;
                $isTemplate = str_contains($type, 'template');
                $isListPreset = $type === NodeType::MessageListPreset->value;
                $outbound[] = [
                    'type' => $isListPreset ? 'list' : ($isTemplate ? 'template' : 'text'),
                    'body' => $config['body'] ?? $config['text'] ?? $config['template_name'] ?? $config['preset_name'] ?? '',
                    'template_name' => $config['template_name'] ?? null,
                    'preset_id' => $config['preset_id'] ?? null,
                    'preset_name' => $config['preset_name'] ?? null,
                ];
                $trace[] = ['node_id' => $nodeId, 'event' => 'completed'];

                if (
                    in_array($type, [
                        NodeType::MessageTemplate->value,
                        NodeType::MessageInteractiveButtons->value,
                        NodeType::MessageListPreset->value,
                    ], true)
                    && (
                        $this->nodeHasButtonReplies($edgesByFrom, $nodeId)
                        || ! empty($config['capture_var'])
                    )
                ) {
                    $this->markWaitingForReply($session, $nodeId);
                }

                $next = $this->findEdgeTarget($edgesByFrom, $nodeId, 'completed');
                $session->current_node_id = $next;
                if ($next === null) {
                    break;
                }

                continue;
            }

            if ($type === NodeType::LogicSetVar->value) {
                $varName = $config['var'] ?? 'branch';
                if (is_string($varName) && $varName !== '') {
                    $vars = $session->vars ?? [];
                    $vars[$varName] = $config['value'] ?? '';
                    $session->vars = $vars;
                }
                $trace[] = ['node_id' => $session->current_node_id, 'event' => 'completed'];
                $session->current_node_id = $this->findEdgeTarget(
                    $edgesByFrom,
                    $session->current_node_id,
                    'completed'
                );

                continue;
            }

            if ($type === NodeType::LogicRouteByVar->value) {
                $trace[] = ['node_id' => $session->current_node_id, 'event' => 'route'];
                $session->current_node_id = $this->findRouteByVarTarget(
                    $session->current_node_id,
                    $config,
                    $session,
                    $edgesByFrom
                );

                continue;
            }

            if ($type === NodeType::IntegrationApi->value) {
                $trace[] = ['node_id' => $session->current_node_id, 'event' => 'completed'];
                $meta = $session->meta ?? [];
                $meta['last_api'] = ['simulated' => true, 'url' => $config['url'] ?? ''];
                $session->meta = $meta;
                $session->current_node_id = $this->findEdgeTarget($edgesByFrom, $session->current_node_id, 'completed');

                continue;
            }

            if ($type === NodeType::LogicCondition->value) {
                $event = $this->evaluateCondition($config, $session) ? 'yes' : 'no';
                $trace[] = ['node_id' => $session->current_node_id, 'event' => $event];
                $session->current_node_id = $this->findEdgeTarget($edgesByFrom, $session->current_node_id, $event);

                continue;
            }

            if ($type === NodeType::FlowEnd->value) {
                $clear = $config['clear_vars'] ?? [];
                if (is_array($clear) && $clear !== []) {
                    $vars = $session->vars ?? [];
                    foreach ($clear as $key) {
                        unset($vars[$key]);
                    }
                    $session->vars = $vars;
                }
                $trace[] = ['node_id' => $session->current_node_id, 'event' => 'completed'];
                $session->current_node_id = null;
                $session->awaiting_input = false;
                break;
            }

            $trace[] = ['node_id' => $session->current_node_id, 'event' => 'completed'];
            $session->current_node_id = $this->findEdgeTarget($edgesByFrom, $session->current_node_id, 'completed');
        }

        $ttl = (int) config('chatbot.session_ttl_minutes', 1440);
        $session->expires_at = Carbon::now()->addMinutes($ttl);
        $session->save();

        return new SimulationStepResult(
            waId: $waId,
            flowVersionId: $flow->id,
            currentNodeId: $session->current_node_id,
            awaitingInput: (bool) $session->awaiting_input,
            vars: $session->vars ?? [],
            outboundPreview: $outbound,
            trace: $trace,
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodesById
     * @param  array<string, mixed>  $definition
     */
    protected function matchTrigger(string $message, array $nodesById, array $definition): ?string
    {
        $normalized = mb_strtolower(trim($message));
        $triggers = [];
        foreach ($nodesById as $id => $node) {
            $type = $node['type'] ?? '';
            if (! str_starts_with((string) $type, 'trigger.')) {
                continue;
            }
            $triggers[] = ['id' => $id, 'node' => $node];
        }

        if ($triggers === []) {
            return null;
        }

        $policy = $definition['entry']['trigger_evaluation'] ?? config('chatbot.trigger_policy', 'first_match');

        if ($policy === 'best_match') {
            $best = null;
            $bestScore = 0.0;
            foreach ($triggers as $trigger) {
                $score = $this->triggerScore($normalized, $trigger['node']);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $trigger['id'];
                }
            }

            return $bestScore >= (float) config('chatbot.fuzzy_threshold', 0.85) ? $best : null;
        }

        foreach ($triggers as $trigger) {
            if ($this->triggerScore($normalized, $trigger['node']) >= (float) config('chatbot.fuzzy_threshold', 0.85)) {
                return $trigger['id'];
            }
        }

        foreach ($triggers as $trigger) {
            if (($trigger['node']['type'] ?? '') === NodeType::TriggerDefault->value) {
                return $trigger['id'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    protected function triggerScore(string $message, array $node): float
    {
        $type = $node['type'] ?? '';
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];

        if ($type === NodeType::TriggerDefault->value) {
            return $message === '' ? 1.0 : 0.0;
        }

        if ($type === NodeType::TriggerRegex->value) {
            $pattern = $config['pattern'] ?? '';
            if (is_string($pattern) && $pattern !== '' && @preg_match($pattern, $message)) {
                return preg_match($pattern, $message) === 1 ? 1.0 : 0.0;
            }

            return 0.0;
        }

        $keywords = $config['keywords'] ?? [];
        if (! is_array($keywords)) {
            return 0.0;
        }

        $best = 0.0;
        foreach ($keywords as $keyword) {
            $kw = mb_strtolower(trim((string) $keyword));
            if ($kw === '') {
                continue;
            }
            if ($message === $kw) {
                return 1.0;
            }
            similar_text($message, $kw, $percent);
            $best = max($best, $percent / 100);
        }

        return $best;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $edgesByFrom
     */
    protected function findEdgeTarget(array $edgesByFrom, string $from, string $event): ?string
    {
        foreach ($edgesByFrom[$from] ?? [] as $edge) {
            if (($edge['event'] ?? '') === $event) {
                $to = $edge['to'] ?? null;

                return is_string($to) ? $to : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function evaluateCondition(array $config, ConversationSession $session): bool
    {
        $operator = $config['operator'] ?? 'equals';
        $expected = $config['value'] ?? null;
        $actual = null;

        if (($config['source'] ?? '') === 'last_api_response') {
            $actual = data_get($session->meta, 'last_api.'.($config['path'] ?? ''));
        } else {
            $path = $config['path'] ?? '';
            if (is_string($path) && str_starts_with($path, 'vars.')) {
                $actual = data_get($session->vars, substr($path, 5));
            }
        }

        return match ($operator) {
            'not_equals' => $actual != $expected,
            'contains' => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            default => $actual == $expected,
        };
    }
}
