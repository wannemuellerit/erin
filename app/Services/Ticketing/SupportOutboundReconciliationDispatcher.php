<?php

namespace App\Services\Ticketing;

use App\Jobs\SyncSupportMessageToProvider;
use App\Jobs\SyncSupportTicketToProvider;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Contracts\Bus\Dispatcher;

final class SupportOutboundReconciliationDispatcher
{
    public function __construct(private readonly Dispatcher $bus) {}

    /**
     * @return array{tickets: int, messages: int}
     */
    public function dispatchDue(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        $ticketIds = SupportTicket::query()
            ->whereNull('external_id')
            ->whereDoesntHave(
                'openingMessage.files',
                fn ($query) => $query->whereIn(
                    'scan_result',
                    ['infected', 'rejected', 'scan_failed'],
                ),
            )
            ->where(function ($query): void {
                $query->where('sync_status', 'pending')
                    ->orWhere(function ($query): void {
                        $query->whereIn('sync_status', ['syncing', 'failed'])
                            ->where(function ($query): void {
                                $query->whereNull(
                                    'external_reconcile_not_before',
                                )->orWhere(
                                    'external_reconcile_not_before',
                                    '<=',
                                    now(),
                                );
                            });
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ticketIds as $ticketId) {
            $this->bus->dispatch(new SyncSupportTicketToProvider((int) $ticketId));
        }

        $messageIds = SupportTicketMessage::query()
            ->whereNull('external_article_id')
            ->where('source', 'erin')
            ->whereDoesntHave(
                'files',
                fn ($query) => $query->whereIn(
                    'scan_result',
                    ['infected', 'rejected', 'scan_failed'],
                ),
            )
            ->where(function ($query): void {
                $query->where('delivery_status', 'pending')
                    ->orWhere(function ($query): void {
                        $query->whereIn(
                            'delivery_status',
                            ['sending', 'failed'],
                        )->where(function ($query): void {
                            $query->whereNull(
                                'external_reconcile_not_before',
                            )->orWhere(
                                'external_reconcile_not_before',
                                '<=',
                                now(),
                            );
                        });
                    });
            })
            ->whereHas(
                'supportTicket',
                fn ($query) => $query->whereNotNull('external_id'),
            )
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('support_ticket_messages as earlier_message')
                    ->whereColumn(
                        'earlier_message.support_ticket_id',
                        'support_ticket_messages.support_ticket_id',
                    )
                    ->whereColumn(
                        'earlier_message.id',
                        '<',
                        'support_ticket_messages.id',
                    );
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($messageIds as $messageId) {
            $this->bus->dispatch(new SyncSupportMessageToProvider((int) $messageId));
        }

        return [
            'tickets' => $ticketIds->count(),
            'messages' => $messageIds->count(),
        ];
    }
}
