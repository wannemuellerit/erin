<?php

namespace App\Services\Ticketing;

use App\Jobs\ProcessSupportZammadWebhookInbox;
use App\Models\SupportZammadWebhookInbox;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;

final class SupportZammadWebhookInboxDispatcher
{
    public function __construct(
        private readonly Dispatcher $bus,
        private readonly SupportZammadWebhookInboxRetention $retention,
    ) {}

    public function dispatchForExternalTicket(
        string $externalTicketId,
        int $limit = 100,
    ): int {
        return $this->dispatchQuery(
            SupportZammadWebhookInbox::query()
                ->where('external_ticket_id', $externalTicketId),
            $limit,
        );
    }

    public function dispatchPending(int $limit = 100): int
    {
        return $this->dispatchQuery(
            SupportZammadWebhookInbox::query()
                ->whereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('support_tickets')
                        ->where('external_system', 'zammad')
                        ->whereColumn(
                            'support_tickets.external_id',
                            'support_zammad_webhook_inbox.external_ticket_id',
                        );
                }),
            $limit,
        );
    }

    /**
     * @param  Builder<SupportZammadWebhookInbox>  $query
     */
    private function dispatchQuery(Builder $query, int $limit): int
    {
        $this->retention->terminalizeExpired();

        SupportZammadWebhookInbox::query()
            ->whereNull('processed_at')
            ->whereNull('terminal_at')
            ->where(
                'attempts',
                '>=',
                ProcessSupportZammadWebhookInbox::MAX_ATTEMPTS,
            )
            ->where(function ($query): void {
                $query->whereNull('locked_at')
                    ->orWhere('locked_at', '<=', now()->subMinutes(10));
            })
            ->update([
                'locked_at' => null,
                'terminal_at' => now(),
                'last_error' => 'Die Zammad-Webhook-Wiedergabe wurde nach einem unterbrochenen Maximalversuch beendet.',
                'raw_payload' => '',
                'updated_at' => now(),
            ]);

        $ids = $query
            ->whereNull('processed_at')
            ->whereNull('terminal_at')
            ->where(
                'attempts',
                '<',
                ProcessSupportZammadWebhookInbox::MAX_ATTEMPTS,
            )
            ->where('available_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('locked_at')
                    ->orWhere('locked_at', '<=', now()->subMinutes(10));
            })
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->pluck('id');

        foreach ($ids as $id) {
            $this->bus->dispatch(
                new ProcessSupportZammadWebhookInbox((int) $id),
            );
        }

        return $ids->count();
    }
}
