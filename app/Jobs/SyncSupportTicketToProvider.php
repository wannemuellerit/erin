<?php

namespace App\Jobs;

use App\Contracts\TicketingProvider;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Services\Ticketing\SupportSyncLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncSupportTicketToProvider implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 120, 300];

    public function __construct(public readonly int $ticketId) {}

    public function uniqueId(): string
    {
        return (string) $this->ticketId;
    }

    public function handle(TicketingProvider $provider): void
    {
        $locks = app(SupportSyncLock::class);
        $lock = $locks->forTicket($this->ticketId);
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
        /** @var SupportTicket $ticket */
        $ticket = SupportTicket::query()
            ->with([
                'requester:id,name,email',
                'messages' => fn ($query) => $query->with('files')->oldest(),
            ])
            ->findOrFail($this->ticketId);

        if ($ticket->external_id !== null) {
            return;
        }

        if (! $provider->enabled()) {
            $ticket->update(['sync_status' => 'disabled', 'sync_error' => null]);

            return;
        }

        $firstMessage = $ticket->messages->first();
        if ($firstMessage === null) {
            return;
        }
        if ($firstMessage->files->contains('scan_result', 'pending')) {
            $this->release(10);

            return;
        }
        if ($firstMessage->files->contains(
            static fn (SupportTicketAttachment $attachment): bool => $attachment->scan_result !== 'clean',
        )) {
            $ticket->update([
                'sync_status' => 'failed',
                'sync_error' => 'Ein Supportanhang wurde nicht sicherheitsgeprüft freigegeben.',
            ]);
            $firstMessage->update(['delivery_status' => 'failed']);

            return;
        }

        $requiresReconciliation = in_array($ticket->sync_status, ['syncing', 'failed'], true);
        $ticket->update(['sync_status' => 'syncing', 'sync_error' => null]);
        $result = $requiresReconciliation
            ? $provider->findTicket($ticket, $firstMessage)
            : null;
        $result ??= $provider->createTicket($ticket, $firstMessage);

        $ticket->update([
            'external_system' => 'zammad',
            'external_id' => $result['external_id'],
            'sync_status' => 'synced',
            'sync_error' => null,
            'last_synced_at' => now(),
        ]);
        $firstMessage->update([
            'external_article_id' => $result['external_article_id'],
            'delivery_status' => 'delivered',
            'delivered_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        SupportTicket::query()->whereKey($this->ticketId)->update([
            'sync_status' => 'failed',
            'sync_error' => mb_substr($exception->getMessage(), 0, 2000),
        ]);
    }
}
