<?php

namespace App\Jobs;

use App\Contracts\TicketingProvider;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use App\Services\Ticketing\SupportSyncLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncSupportMessageToProvider implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 120, 300];

    public function __construct(public readonly int $messageId) {}

    public function uniqueId(): string
    {
        return (string) $this->messageId;
    }

    public function handle(TicketingProvider $provider): void
    {
        $locks = app(SupportSyncLock::class);
        $lock = $locks->forMessage($this->messageId);
        if (! $lock->get()) {
            $this->release($locks->retrySeconds());

            return;
        }

        try {
            $this->synchronize($provider);
        } finally {
            $lock->release();
        }
    }

    private function synchronize(TicketingProvider $provider): void
    {
        /** @var SupportTicketMessage $message */
        $message = SupportTicketMessage::query()
            ->with(['supportTicket', 'author:id,name,email,role', 'files'])
            ->findOrFail($this->messageId);

        if ($message->external_article_id !== null || $message->source === 'zammad') {
            return;
        }

        if (! $provider->enabled()) {
            $message->update(['delivery_status' => 'local_only']);

            return;
        }

        if ($message->files->contains('scan_result', 'pending')) {
            $this->release(10);

            return;
        }
        if ($message->files->contains(
            static fn (SupportTicketAttachment $attachment): bool => $attachment->scan_result !== 'clean',
        )) {
            $message->update(['delivery_status' => 'failed']);

            return;
        }

        if ($message->supportTicket->external_id === null) {
            SyncSupportTicketToProvider::dispatch($message->supportTicket->getKey());
            $this->release(10);

            return;
        }

        $requiresReconciliation = in_array($message->delivery_status, ['sending', 'failed'], true);
        $message->update(['delivery_status' => 'sending']);
        $result = $requiresReconciliation
            ? $provider->findMessage($message->supportTicket, $message)
            : null;
        $result ??= $provider->createMessage($message->supportTicket, $message);

        $message->update([
            'external_article_id' => $result['external_article_id'],
            'delivery_status' => 'delivered',
            'delivered_at' => now(),
        ]);
        $message->supportTicket->update([
            'sync_status' => 'synced',
            'sync_error' => null,
            'last_synced_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        SupportTicketMessage::query()->whereKey($this->messageId)->update([
            'delivery_status' => 'failed',
        ]);
    }
}
