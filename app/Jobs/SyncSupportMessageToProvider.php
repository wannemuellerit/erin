<?php

namespace App\Jobs;

use App\Contracts\TicketingProvider;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use App\Services\Ticketing\SupportSyncLock;
use App\Services\Ticketing\ZammadArticleReceiptRecorder;
use App\Services\Ticketing\ZammadReconciliationPolicy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SyncSupportMessageToProvider implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 60, 120, 300];

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
            $message->update([
                'delivery_status' => 'local_only',
                'external_reconcile_attempts' => 0,
                'external_reconcile_not_before' => null,
            ]);

            return;
        }

        if ($message->files->contains('scan_result', 'pending')) {
            $this->release(10);

            return;
        }
        if ($message->files->contains(
            static fn (SupportTicketAttachment $attachment): bool => $attachment->scan_result !== 'clean',
        )) {
            $message->update([
                'delivery_status' => 'failed',
                'external_reconcile_not_before' => null,
            ]);

            return;
        }

        if ($message->supportTicket->external_id === null) {
            SyncSupportTicketToProvider::dispatch($message->supportTicket->getKey());
            $this->release(10);

            return;
        }

        $policy = app(ZammadReconciliationPolicy::class);
        $requiresReconciliation = in_array($message->delivery_status, ['sending', 'failed'], true);
        if ($requiresReconciliation) {
            $waitSeconds = $policy->secondsUntil($message->external_reconcile_not_before);
            if ($waitSeconds > 0) {
                $this->release($waitSeconds);

                return;
            }

            $message->update([
                'delivery_status' => 'sending',
                'external_reconcile_not_before' => now()->addSeconds(
                    $policy->intervalSeconds(),
                ),
            ]);
            $result = $provider->findMessage($message->supportTicket, $message);
            if ($result !== null) {
                $this->markDelivered($this->externalArticleId($result));

                return;
            }

            $misses = $message->external_reconcile_attempts + 1;
            $message->update(['external_reconcile_attempts' => $misses]);
            if ($misses < $policy->requiredMisses()) {
                $this->release($policy->intervalSeconds());

                return;
            }
        }

        $message->update([
            'delivery_status' => 'sending',
            'external_reconcile_attempts' => 0,
            'external_reconcile_not_before' => now()->addSeconds(
                $policy->initialDelaySeconds(),
            ),
        ]);
        $result = $provider->createMessage($message->supportTicket, $message);
        $this->markDelivered($this->externalArticleId($result));
    }

    /**
     * Keep the provider boundary defensive even though the interface documents
     * the successful response shape.
     *
     * @param  array<string, mixed>  $result
     */
    private function externalArticleId(array $result): mixed
    {
        return $result['external_article_id'] ?? null;
    }

    private function markDelivered(mixed $externalArticleId): void
    {
        if (
            (! is_string($externalArticleId) && ! is_int($externalArticleId))
            || preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $externalArticleId) !== 1
        ) {
            throw new RuntimeException(
                'Zammad hat keine gültige ID für den Supportartikel zurückgegeben.',
            );
        }
        $externalArticleId = (string) $externalArticleId;
        $ticketId = SupportTicketMessage::query()
            ->whereKey($this->messageId)
            ->value('support_ticket_id');
        if (
            (! is_int($ticketId) && ! is_string($ticketId))
            || preg_match('/\A[1-9][0-9]*\z/D', (string) $ticketId) !== 1
        ) {
            throw new RuntimeException(
                'Die Erin-Nachricht besitzt kein gültiges Supportticket.',
            );
        }
        $ticketId = (int) $ticketId;

        DB::transaction(function () use ($externalArticleId, $ticketId): void {
            /** @var SupportTicket $ticket */
            $ticket = SupportTicket::query()
                ->lockForUpdate()
                ->findOrFail($ticketId);
            /** @var SupportTicketMessage $message */
            $message = SupportTicketMessage::query()
                ->where('support_ticket_id', $ticket->getKey())
                ->lockForUpdate()
                ->findOrFail($this->messageId);

            if (
                $message->external_article_id !== null
                && ! hash_equals($message->external_article_id, $externalArticleId)
            ) {
                throw new RuntimeException(
                    'Die Erin-Nachricht wurde mit widersprüchlichen Zammad-Artikeln bestätigt.',
                );
            }

            $conflict = SupportTicketMessage::query()
                ->where('external_article_id', $externalArticleId)
                ->whereKeyNot($message->getKey())
                ->exists();
            if ($conflict) {
                throw new RuntimeException(
                    'Der Zammad-Artikel wurde bereits einer anderen Erin-Nachricht zugeordnet.',
                );
            }

            $message->forceFill([
                'external_article_id' => $externalArticleId,
                'delivery_status' => 'delivered',
                'delivered_at' => $message->delivered_at ?? now(),
                'external_reconcile_attempts' => 0,
                'external_reconcile_not_before' => null,
            ])->save();
            app(ZammadArticleReceiptRecorder::class)->record(
                $message->support_ticket_id,
                $externalArticleId,
                $message->is_internal,
            );
            $ticket->forceFill([
                'sync_status' => 'synced',
                'sync_error' => null,
                'last_synced_at' => now(),
            ])->save();
        }, 3);
    }

    public function failed(Throwable $exception): void
    {
        SupportTicketMessage::query()
            ->whereKey($this->messageId)
            ->whereNull('external_article_id')
            ->where('delivery_status', '!=', 'delivered')
            ->update([
                'delivery_status' => 'failed',
                'external_reconcile_not_before' => now()->addSeconds(
                    app(ZammadReconciliationPolicy::class)->initialDelaySeconds(),
                ),
            ]);
    }
}
