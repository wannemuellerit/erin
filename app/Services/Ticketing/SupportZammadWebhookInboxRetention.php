<?php

namespace App\Services\Ticketing;

use App\Models\SupportZammadWebhookInbox;
use Illuminate\Database\Eloquent\Builder;

final class SupportZammadWebhookInboxRetention
{
    public function terminalizeExpired(?int $inboxId = null): int
    {
        return SupportZammadWebhookInbox::query()
            ->when(
                $inboxId !== null,
                fn (Builder $query) => $query->whereKey($inboxId),
            )
            ->whereNull('processed_at')
            ->whereNull('terminal_at')
            ->where(
                'created_at',
                '<=',
                now()->subHours($this->retentionHours()),
            )
            ->where(function ($query): void {
                $query->whereNull('locked_at')
                    ->orWhere('locked_at', '<=', now()->subMinutes(10));
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('support_tickets')
                    ->where('external_system', 'zammad')
                    ->whereColumn(
                        'support_tickets.external_id',
                        'support_zammad_webhook_inbox.external_ticket_id',
                    );
            })
            ->update([
                'locked_at' => null,
                'terminal_at' => now(),
                'last_error' => 'Für den gespeicherten Zammad-Webhook wurde innerhalb der maximalen Wartezeit kein lokales Ticket zugeordnet.',
                'raw_payload' => '',
                'updated_at' => now(),
            ]);
    }

    public function retentionHours(): int
    {
        return max(
            1,
            (int) config(
                'services.zammad.unmatched_webhook_retention_hours',
                24,
            ),
        );
    }
}
