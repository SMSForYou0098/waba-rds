<?php

namespace App\Services\Messaging\LegacyApi;

use App\Models\Chat\ChatHistory;
use App\Models\Report\ApiTemplateReport;

class LegacyApiReportSideEffectsService
{
    /**
     * @param  list<string>|null  $bodyValues
     * @param  list<string>|null  $buttonValues
     */
    public function record(
        int $outReportId,
        int $userId,
        ?string $templateName,
        string $messageId,
        ?string $mediaId,
        ?string $mediaUrl,
        ?array $bodyValues,
        ?array $buttonValues,
        ?string $reportId,
    ): void {
        ChatHistory::query()->create([
            'user_id' => $userId,
            'message' => $templateName,
            'message_id' => $messageId,
            'type' => 'template',
            'agent_id' => null,
            'out_report_id' => $outReportId,
            'reply_id' => null,
            'report_id' => $reportId,
        ]);

        ApiTemplateReport::query()->create([
            'report_id' => $outReportId,
            'template_name' => $templateName,
            'media_id' => $mediaId,
            'media_url' => $mediaUrl,
            'body_values' => json_encode($bodyValues),
            'button_values' => json_encode($buttonValues),
        ]);
    }
}
