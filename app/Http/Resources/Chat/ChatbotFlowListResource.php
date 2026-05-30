<?php

namespace App\Http\Resources\Chat;

use App\Models\Chat\ChatbotFlowVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ChatbotFlowVersion */
class ChatbotFlowListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $definition = is_array($this->definition) ? $this->definition : [];
        $nodes = $definition['nodes'] ?? [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'group_id' => $this->group_id,
            'version' => $this->version,
            'status' => $this->status?->value ?? $this->status,
            'is_active' => $this->is_active,
            'node_count' => is_array($nodes) ? count($nodes) : 0,
            'trigger_keywords' => $this->extractTriggerKeywords($nodes),
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, mixed>  $nodes
     * @return array<int, string>
     */
    protected function extractTriggerKeywords(array $nodes): array
    {
        $keywords = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $type = $node['type'] ?? '';
            if (! str_starts_with((string) $type, 'trigger.')) {
                continue;
            }
            $configKeywords = $node['config']['keywords'] ?? [];
            if (is_array($configKeywords)) {
                foreach ($configKeywords as $kw) {
                    if (is_string($kw) && $kw !== '') {
                        $keywords[] = $kw;
                    }
                }
            }
        }

        return array_values(array_unique($keywords));
    }
}
