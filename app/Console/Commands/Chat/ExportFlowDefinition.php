<?php

namespace App\Console\Commands\Chat;

use App\Models\Chat\ChatbotFlowVersion;
use Illuminate\Console\Command;

class ExportFlowDefinition extends Command
{
    protected $signature = 'chatbot:flow-export {id : Flow version id}';

    protected $description = 'Export a chatbot flow definition as JSON';

    public function handle(): int
    {
        $flow = ChatbotFlowVersion::query()->find($this->argument('id'));

        if (! $flow) {
            $this->error('Flow not found.');

            return self::FAILURE;
        }

        $this->line(json_encode($flow->definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
