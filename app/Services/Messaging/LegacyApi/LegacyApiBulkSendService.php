<?php

namespace App\Services\Messaging\LegacyApi;

use App\Data\Messaging\LegacyApiBulkSendResult;
use App\Data\Messaging\LegacyApiSendContext;

class LegacyApiBulkSendService
{
    public function __construct(
        private readonly LegacyApiMessageDispatchService $dispatchService,
    ) {}

    public function sendAll(LegacyApiSendContext $context): LegacyApiBulkSendResult
    {
        $successCount = 0;
        $failureCount = 0;
        $failures = [];

        foreach ($context->recipients as $to) {
            if ($to === '' || ! is_numeric($to) || ! in_array(strlen($to), [10, 12], true)) {
                $failureCount++;
                $failures[] = [
                    'number' => $to,
                    'error' => 'Invalid phone number format',
                    'error_code' => 'SF2',
                    'error_type' => 'validation_error',
                ];

                continue;
            }

            $result = $this->dispatchService->sendToRecipient($context, $to);

            if ($result['ok']) {
                $successCount++;

                continue;
            }

            $failureCount++;
            $failures[] = [
                'number' => $to,
                'error' => $result['error'] ?? 'Unknown error',
                'error_code' => $result['error_code'] ?? 'UNKNOWN',
                'error_type' => $result['error_type'] ?? 'api_error',
            ];
        }

        return new LegacyApiBulkSendResult($successCount, $failureCount, $failures);
    }
}
