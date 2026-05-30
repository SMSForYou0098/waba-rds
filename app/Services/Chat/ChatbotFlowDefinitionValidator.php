<?php

namespace App\Services\Chat;

use App\Data\Chat\FlowValidationResult;
use App\Enums\Chat\NodeType;

class ChatbotFlowDefinitionValidator
{
    /**
     * @param  array<string, mixed>  $definition
     */
    public function validate(array $definition): FlowValidationResult
    {
        $errors = [];
        $warnings = [];

        $nodes = $definition['nodes'] ?? [];
        $edges = $definition['edges'] ?? [];

        if (! is_array($nodes) || count($nodes) === 0) {
            return new FlowValidationResult(false, [
                ['code' => 'empty_graph', 'message' => 'Flow must contain at least one node.'],
            ]);
        }

        if (! is_array($edges)) {
            $edges = [];
        }

        $nodeIds = [];
        $nodeById = [];
        $allowedTypes = config('chatbot.node_types', NodeType::values());

        foreach ($nodes as $index => $node) {
            if (! is_array($node)) {
                $errors[] = ['code' => 'invalid_node', 'message' => "Node at index {$index} must be an object."];

                continue;
            }

            $id = $node['id'] ?? null;
            $type = $node['type'] ?? null;

            if (! is_string($id) || $id === '') {
                $errors[] = ['code' => 'missing_node_id', 'message' => "Node at index {$index} is missing id."];

                continue;
            }

            if (isset($nodeIds[$id])) {
                $errors[] = [
                    'code' => 'duplicate_node_id',
                    'message' => "Duplicate node id: {$id}.",
                    'node_id' => $id,
                ];
            }

            $nodeIds[$id] = true;
            $nodeById[$id] = $node;

            if (! is_string($type) || ! in_array($type, $allowedTypes, true)) {
                $errors[] = [
                    'code' => 'unknown_node_type',
                    'message' => "Unknown node type: {$type}.",
                    'node_id' => $id,
                ];
            }

            $config = $node['config'] ?? [];
            if (! is_array($config)) {
                $errors[] = [
                    'code' => 'invalid_config',
                    'message' => 'Node config must be an object.',
                    'node_id' => $id,
                ];

                continue;
            }

            if ($type === NodeType::IntegrationApi->value && empty($config['url'])) {
                $errors[] = [
                    'code' => 'missing_api_url',
                    'message' => 'integration.api node requires url in config.',
                    'node_id' => $id,
                ];
            }

            if ($type === NodeType::LogicAsk->value && empty($config['var'])) {
                $errors[] = [
                    'code' => 'missing_ask_var',
                    'message' => 'logic.ask node requires var in config.',
                    'node_id' => $id,
                ];
            }
        }

        $adjacency = [];
        $reverseAdjacency = [];
        $triggerIds = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = $node['id'] ?? null;
            $type = $node['type'] ?? null;
            if (! is_string($id)) {
                continue;
            }
            $adjacency[$id] = [];
            $reverseAdjacency[$id] = [];
            if (is_string($type) && str_starts_with($type, 'trigger.')) {
                $triggerIds[] = $id;
            }
        }

        foreach ($edges as $index => $edge) {
            if (! is_array($edge)) {
                $errors[] = ['code' => 'invalid_edge', 'message' => "Edge at index {$index} must be an object."];

                continue;
            }

            $from = $edge['from'] ?? null;
            $to = $edge['to'] ?? null;

            if (! is_string($from) || ! isset($nodeById[$from])) {
                $errors[] = [
                    'code' => 'invalid_edge_from',
                    'message' => "Edge references missing from node: {$from}.",
                ];
            }

            if ($to !== null && (! is_string($to) || ! isset($nodeById[$to]))) {
                $errors[] = [
                    'code' => 'invalid_edge_to',
                    'message' => "Edge references missing to node: {$to}.",
                ];
            }

            if (is_string($from) && isset($adjacency[$from]) && is_string($to)) {
                $adjacency[$from][] = $to;
                $reverseAdjacency[$to][] = $from;
            }
        }

        $reachableFromTriggers = [];
        $queue = $triggerIds;
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($reachableFromTriggers[$current])) {
                continue;
            }
            $reachableFromTriggers[$current] = true;
            foreach ($adjacency[$current] ?? [] as $next) {
                if (! isset($reachableFromTriggers[$next])) {
                    $queue[] = $next;
                }
            }
        }

        foreach (array_keys($nodeById) as $nodeId) {
            $node = $nodeById[$nodeId];
            $type = $node['type'] ?? '';
            if (str_starts_with((string) $type, 'trigger.')) {
                continue;
            }
            if (! isset($reachableFromTriggers[$nodeId])) {
                $errors[] = [
                    'code' => 'orphan_node',
                    'message' => 'Node is not reachable from any trigger.',
                    'node_id' => $nodeId,
                ];
            }
        }

        foreach ($nodeById as $nodeId => $node) {
            $type = $node['type'] ?? '';
            if ($type === NodeType::FlowEnd->value) {
                continue;
            }
            if (($adjacency[$nodeId] ?? []) === []) {
                $warnings[] = [
                    'code' => 'no_outgoing_edge',
                    'message' => 'Node has no outgoing edges.',
                    'node_id' => $nodeId,
                ];
            }
        }

        foreach (array_keys($nodeById) as $nodeId) {
            if ($this->hasCycleWithoutExit($nodeId, $adjacency, $nodeById)) {
                $warnings[] = [
                    'code' => 'possible_cycle',
                    'message' => 'Node participates in a cycle without a flow.end exit.',
                    'node_id' => $nodeId,
                ];
                break;
            }
        }

        return new FlowValidationResult(count($errors) === 0, $errors, $warnings);
    }

    /**
     * @param  array<string, array<int, string>>  $adjacency
     * @param  array<string, array<string, mixed>>  $nodeById
     */
    protected function hasCycleWithoutExit(string $start, array $adjacency, array $nodeById): bool
    {
        $visited = [];
        $stack = [$start];

        while ($stack !== []) {
            $current = array_pop($stack);
            if (isset($visited[$current])) {
                return true;
            }
            $visited[$current] = true;
            $type = $nodeById[$current]['type'] ?? '';
            if ($type === NodeType::FlowEnd->value) {
                continue;
            }
            foreach ($adjacency[$current] ?? [] as $next) {
                $stack[] = $next;
            }
        }

        return false;
    }
}
