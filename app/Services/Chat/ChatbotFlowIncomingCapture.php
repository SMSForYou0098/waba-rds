<?php

namespace App\Services\Chat;

use App\Models\Chat\ConversationSession;

/**
 * Store plain-text incoming (from webhook parser) into session vars and resolve button/route edges.
 */
trait ChatbotFlowIncomingCapture
{
    protected function normalizeIncoming(string $message): string
    {
        return trim($message);
    }

    protected function storeIncomingVar(ConversationSession $session, string $varName, string $value): void
    {
        $vars = $session->vars ?? [];
        $vars[$varName] = $value;
        $session->vars = $vars;

        $meta = $session->meta ?? [];
        $meta['last_incoming'] = $value;
        $session->meta = $meta;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>|null  $edge
     */
    protected function resolveCaptureVar(array $node, ?array $edge): ?string
    {
        if ($edge !== null) {
            $edgeVar = $edge['capture']['var'] ?? null;
            if (is_string($edgeVar) && $edgeVar !== '') {
                return $edgeVar;
            }
        }

        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $nodeVar = $config['capture_var'] ?? null;

        return is_string($nodeVar) && $nodeVar !== '' ? $nodeVar : null;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $edgesByFrom
     * @return array<string, mixed>|null
     */
    protected function findButtonReplyEdge(array $edgesByFrom, string $from, string $message): ?array
    {
        $normalized = mb_strtolower($this->normalizeIncoming($message));

        foreach ($edgesByFrom[$from] ?? [] as $edge) {
            if (($edge['event'] ?? '') !== 'button_reply') {
                continue;
            }
            $payload = (string) ($edge['match']['payload'] ?? '');
            if (mb_strtolower(trim($payload)) === $normalized) {
                return $edge;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $edgesByFrom
     */
    protected function nodeHasButtonReplies(array $edgesByFrom, string $nodeId): bool
    {
        foreach ($edgesByFrom[$nodeId] ?? [] as $edge) {
            if (($edge['event'] ?? '') === 'button_reply') {
                return true;
            }
        }

        return false;
    }

    protected function markWaitingForReply(ConversationSession $session, string $nodeId): void
    {
        $meta = $session->meta ?? [];
        $meta['wait_for_reply_from'] = $nodeId;
        $session->meta = $meta;
    }

    protected function clearWaitingForReply(ConversationSession $session): void
    {
        $meta = $session->meta ?? [];
        unset($meta['wait_for_reply_from']);
        $session->meta = $meta;
    }

    protected function getWaitingForReplyNodeId(ConversationSession $session): ?string
    {
        $id = $session->meta['wait_for_reply_from'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Handle plain-text reply after a template / interactive node (parser output).
     *
     * @param  array<string, array<string, mixed>>  $nodesById
     * @param  array<string, array<int, array<string, mixed>>>  $edgesByFrom
     * @param  array<int, array<string, mixed>>  $trace
     */
    protected function tryHandleIncomingReply(
        ConversationSession $session,
        string $message,
        array $nodesById,
        array $edgesByFrom,
        array &$trace,
    ): bool {
        $waitFrom = $this->getWaitingForReplyNodeId($session);
        if ($waitFrom === null || ! isset($nodesById[$waitFrom])) {
            return false;
        }

        $incoming = $this->normalizeIncoming($message);
        if ($incoming === '') {
            return false;
        }

        $node = $nodesById[$waitFrom];
        $edge = $this->findButtonReplyEdge($edgesByFrom, $waitFrom, $incoming);
        $captureVar = $this->resolveCaptureVar($node, $edge);

        if ($captureVar !== null) {
            $this->storeIncomingVar($session, $captureVar, $incoming);
            $trace[] = [
                'node_id' => $waitFrom,
                'event' => 'capture',
                'var' => $captureVar,
                'value' => $incoming,
            ];
        }

        $this->clearWaitingForReply($session);

        if ($edge !== null && ! empty($edge['to']) && is_string($edge['to'])) {
            $trace[] = ['node_id' => $waitFrom, 'event' => 'button_reply', 'match' => $edge['match'] ?? []];
            $session->current_node_id = $edge['to'];

            return true;
        }

        $session->current_node_id = null;

        return true;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, array<int, array<string, mixed>>>  $edgesByFrom
     */
    protected function findRouteByVarTarget(
        string $nodeId,
        array $config,
        ConversationSession $session,
        array $edgesByFrom,
    ): ?string {
        $varName = $config['var'] ?? 'branch';
        $value = (string) data_get($session->vars, $varName, '');
        $normalized = mb_strtolower(trim($value));

        foreach ($edgesByFrom[$nodeId] ?? [] as $edge) {
            if (($edge['event'] ?? '') !== 'route') {
                continue;
            }
            $branch = (string) ($edge['match']['branch'] ?? $edge['match']['payload'] ?? '');
            if (mb_strtolower(trim($branch)) === $normalized && ! empty($edge['to'])) {
                return is_string($edge['to']) ? $edge['to'] : null;
            }
        }

        return null;
    }

}
