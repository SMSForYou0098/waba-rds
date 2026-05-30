<?php

namespace App\Data\Chat;

readonly class FlowValidationResult
{
    /**
     * @param  array<int, array{code: string, message: string, node_id?: string}>  $errors
     * @param  array<int, array{code: string, message: string, node_id?: string}>  $warnings
     */
    public function __construct(
        public bool $valid,
        public array $errors = [],
        public array $warnings = [],
    ) {}

    /**
     * @return array{valid: bool, errors: array<int, array<string, string>>, warnings: array<int, array<string, string>>}
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}
