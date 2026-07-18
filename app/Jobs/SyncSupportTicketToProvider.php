<?php

namespace App\Jobs;

use App\Contracts\TicketingProvider;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use App\Services\Ticketing\SupportSyncLock;
use App\Services\Ticketing\SupportZammadWebhookInboxDispatcher;
use App\Services\Ticketing\ZammadArticleReceiptRecorder;
use App\Services\Ticketing\ZammadReconciliationPolicy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SyncSupportTicketToProvider implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 60, 120, 300];

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
            DB::transaction(function (): void {
                /** @var SupportTicket $ticket */
                $ticket = SupportTicket::query()
                    ->lockForUpdate()
                    ->findOrFail($this->ticketId);
                $ticket->update([
                    'sync_status' => 'disabled',
                    'sync_error' => null,
                    'external_reconcile_attempts' => 0,
                    'external_reconcile_not_before' => null,
                ]);
                SupportTicketMessage::query()
                    ->where('support_ticket_id', $ticket->getKey())
                    ->where('source', 'erin')
                    ->whereNull('external_article_id')
                    ->whereIn(
                        'delivery_status',
                        ['pending', 'sending', 'failed'],
                    )
                    ->update([
                        'delivery_status' => 'local_only',
                        'external_reconcile_attempts' => 0,
                        'external_reconcile_not_before' => null,
                    ]);
            }, 3);

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
                'external_reconcile_not_before' => null,
            ]);
            $firstMessage->update([
                'delivery_status' => 'failed',
                'external_reconcile_not_before' => null,
            ]);

            return;
        }

        $policy = app(ZammadReconciliationPolicy::class);
        $requiresReconciliation = in_array($ticket->sync_status, ['syncing', 'failed'], true);
        if ($requiresReconciliation) {
            $waitSeconds = $policy->secondsUntil($ticket->external_reconcile_not_before);
            if ($waitSeconds > 0) {
                $this->release($waitSeconds);

                return;
            }

            $ticket->update([
                'sync_status' => 'syncing',
                'sync_error' => null,
                'external_reconcile_not_before' => now()->addSeconds(
                    $policy->intervalSeconds(),
                ),
            ]);
            $result = $provider->findTicket($ticket, $firstMessage);
            if ($result !== null) {
                $this->markDelivered($firstMessage, $result);

                return;
            }

            $misses = $ticket->external_reconcile_attempts + 1;
            $ticket->update(['external_reconcile_attempts' => $misses]);
            if ($misses < $policy->requiredMisses()) {
                $this->release($policy->intervalSeconds());

                return;
            }
        }

        $ticket->update([
            'sync_status' => 'syncing',
            'sync_error' => null,
            'external_reconcile_attempts' => 0,
            'external_reconcile_not_before' => now()->addSeconds(
                $policy->initialDelaySeconds(),
            ),
        ]);
        $this->markDelivered(
            $firstMessage,
            $provider->createTicket($ticket, $firstMessage),
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function markDelivered(
        SupportTicketMessage $firstMessage,
        array $result,
    ): void {
        $externalTicketId = $this->externalId(
            $result['external_id'] ?? null,
            'Ticket',
        );
        $externalArticleId = $this->externalId(
            $result['external_article_id'] ?? null,
            'Eröffnungsartikel',
        );

        DB::transaction(function () use (
            $firstMessage,
            $externalTicketId,
            $externalArticleId,
        ): void {
            /** @var SupportTicket $ticket */
            $ticket = SupportTicket::query()
                ->lockForUpdate()
                ->findOrFail($this->ticketId);
            /** @var SupportTicketMessage $message */
            $message = SupportTicketMessage::query()
                ->where('support_ticket_id', $ticket->getKey())
                ->lockForUpdate()
                ->findOrFail($firstMessage->getKey());

            if (
                $ticket->external_id !== null
                && ! hash_equals($ticket->external_id, $externalTicketId)
            ) {
                throw new RuntimeException(
                    'Das Erin-Ticket wurde widersprüchlichen Zammad-Tickets zugeordnet.',
                );
            }
            if (
                $message->external_article_id !== null
                && ! hash_equals($message->external_article_id, $externalArticleId)
            ) {
                throw new RuntimeException(
                    'Die Eröffnungsnachricht wurde widersprüchlichen Zammad-Artikeln zugeordnet.',
                );
            }
            if (SupportTicketMessage::query()
                ->where('external_article_id', $externalArticleId)
                ->whereKeyNot($message->getKey())
                ->exists()) {
                throw new RuntimeException(
                    'Der Zammad-Eröffnungsartikel gehört bereits zu einer anderen Erin-Nachricht.',
                );
            }

            $ticket->forceFill([
                'external_system' => 'zammad',
                'external_id' => $externalTicketId,
                'sync_status' => 'synced',
                'sync_error' => null,
                'last_synced_at' => now(),
                'external_reconcile_attempts' => 0,
                'external_reconcile_not_before' => null,
            ])->save();
            $message->forceFill([
                'external_article_id' => $externalArticleId,
                'delivery_status' => 'delivered',
                'delivered_at' => $message->delivered_at ?? now(),
                'external_reconcile_attempts' => 0,
                'external_reconcile_not_before' => null,
            ])->save();
            app(ZammadArticleReceiptRecorder::class)->record(
                $ticket->getKey(),
                $externalArticleId,
                false,
            );
        }, 3);

        app(SupportZammadWebhookInboxDispatcher::class)
            ->dispatchForExternalTicket($externalTicketId);
    }

    private function externalId(mixed $value, string $label): string
    {
        if (
            (! is_string($value) && ! is_int($value))
            || preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $value) !== 1
        ) {
            throw new RuntimeException(
                sprintf('Zammad hat keine gültige ID für %s zurückgegeben.', $label),
            );
        }

        return (string) $value;
    }

    public function failed(Throwable $exception): void
    {
        SupportTicket::query()
            ->whereKey($this->ticketId)
            ->whereNull('external_id')
            ->update([
                'sync_status' => 'failed',
                'sync_error' => 'Die Synchronisierung mit dem Ticketsystem ist nach mehreren Versuchen fehlgeschlagen.',
                'external_reconcile_not_before' => now()->addSeconds(
                    app(ZammadReconciliationPolicy::class)->initialDelaySeconds(),
                ),
            ]);
    }
}
