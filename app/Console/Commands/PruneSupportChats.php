<?php

namespace App\Console\Commands;

use App\Models\SupportChatSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneSupportChats extends Command
{
    protected $signature = 'erin:support:prune-chats {--execute : Delete chats whose retention period has expired} {--json : Return machine-readable output}';

    protected $description = 'Report or delete expired encrypted support-chat sessions.';

    public function handle(): int
    {
        $query = SupportChatSession::query()->where('retention_expires_at', '<=', now());
        $eligible = (clone $query)->count();
        $deleted = $this->option('execute') ? $query->delete() : 0;
        $result = [
            'eligible' => $eligible,
            'deleted' => $deleted,
            'executed' => (bool) $this->option('execute'),
        ];
        Log::info('support_chat.retention_prune', $result);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            $this->info($this->option('execute')
                ? __(':count abgelaufene Supportchats wurden gelöscht.', ['count' => $deleted])
                : __(':count Supportchats wären zu löschen.', ['count' => $eligible]));
        }

        return self::SUCCESS;
    }
}
