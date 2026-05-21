<?php

namespace App\Data\Messaging;

readonly class LegacyApiBulkSendResult
{
    /**
     * @param  list<array<string, mixed>>  $failures
     */
    public function __construct(
        public int $successCount,
        public int $failureCount,
        public array $failures,
    ) {}
}
