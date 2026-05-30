<?php

namespace App\Console\Commands\Chat;

use App\Services\Chat\ChatbotFlowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportFlowDefinition extends Command
{
    protected $signature = 'chatbot:flow-import {userId : Tenant user id} {path : Path to JSON file}';

    protected $description = 'Import a flow definition JSON file as a new draft';

    public function handle(ChatbotFlowService $flowService): int
    {
        $path = $this->argument('path');

        if (! File::exists($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $definition = json_decode(File::get($path), true);

        if (! is_array($definition)) {
            $this->error('Invalid JSON file.');

            return self::FAILURE;
        }

        $flow = $flowService->createDraft((int) $this->argument('userId'), [
            'name' => $definition['flow_id'] ?? 'Imported flow',
            'definition' => $definition,
        ]);

        $this->info("Imported draft flow id: {$flow->id}");

        return self::SUCCESS;
    }
}
