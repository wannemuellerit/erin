<?php

namespace App\Jobs;

use App\Contracts\TicketingProvider;
use App\Models\SupportTicket;
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
        /** @var SupportTicket $ticket */
        $ticket = SupportTicket::query()
            ->with(['requester:id,name,email', 'messages' => fn ($query) => $query->oldest()])
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
