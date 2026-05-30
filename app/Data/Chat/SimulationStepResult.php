<?php

namespace App\Data\Chat;

readonly class SimulationStepResult
{
    /**
     * @param  array<string, mixed>  $vars
     * @param  array<int, array<string, mixed>>  $outboundPreview
     * @param  array<int, array{node_id: string, event: string}>  $trace
     */
    public function __construct(
        public string $waId,
        public int $flowVersionId,
        public ?string $currentNodeId,
        public bool $awaitingInput,
        public array $vars,
        public array $outboundPreview,
        public array $trace,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'wa_id' => $this->waId,
            'flow_version_id' => $this->flowVersionId,
            'current_node_id' => $this->currentNodeId,
            'awaiting_input' => $this->awaitingInput,
            'vars' => $this->vars,
            'outbound_preview' => $this->outboundPreview,
            'trace' => $this->trace,
        ];
    }
}
