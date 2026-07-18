<?php

namespace App\Services\Ticketing;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class SupportSyncLock
{
    public function forTicket(int $ticketId): Lock
    {
        return Cache::lock(
            "support-sync:ticket:{$ticketId}",
            $this->lockSeconds(),
        );
    }

    public function forMessage(int $messageId): Lock
    {
        return Cache::lock(
            "support-sync:message:{$messageId}",
            $this->lockSeconds(),
        );
    }

    public function retrySeconds(): int
    {
        return max(1, (int) config('support.sync.lock_retry_seconds', 5));
    }

    private function lockSeconds(): int
    {
        return max(30, (int) config('support.sync.lock_seconds', 300));
    }
}
