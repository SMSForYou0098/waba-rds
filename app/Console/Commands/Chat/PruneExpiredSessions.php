<?php

namespace App\Console\Commands\Chat;

use App\Models\Chat\ConversationSession;
use Illuminate\Console\Command;

class PruneExpiredSessions extends Command
{
    protected $signature = 'chatbot:session-prune';

    protected $description = 'Delete expired conversation sessions';

    public function handle(): int
    {
        $deleted = ConversationSession::query()->expired()->delete();

        $this->info("Pruned {$deleted} expired conversation session(s).");

        return self::SUCCESS;
    }
}
