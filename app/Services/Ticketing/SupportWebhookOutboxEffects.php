<?php

namespace App\Services\Ticketing;

use App\Events\SupportTicketMessageCreated;
use App\Jobs\ImportZammadAttachment;
use App\Models\SupportWebhookOutbox;
use App\Notifications\ActivityNotification;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use RuntimeException;

class SupportWebhookOutboxEffects
{
    public function __construct(private readonly Dispatcher $bus) {}

    public function deliver(SupportWebhookOutbox $entry): void
    {
        $entry->loadMissing([
            'message.supportTicket.requester',
            'message.files',
            'attachment',
            'recipient',
        ]);

        match ($entry->effect) {
            'broadcast' => $this->broadcast($entry),
            'attachment_import' => $this->importAttachment($entry),
            'notification_database' => $this->notify($entry, 'database'),
            'notification_broadcast' => $this->notify($entry, 'broadcast'),
            'notification_mail' => $this->notify($entry, 'mail'),
            'notification_push' => $this->notify($entry, WebPushChannel::class),
            default => throw new RuntimeException('Der Support-Outbox-Effekt ist unbekannt.'),
        };
    }

    private function broadcast(SupportWebhookOutbox $entry): void
    {
        SupportTicketMessageCreated::dispatch(
            $entry->message,
            'support-outbox-'.$entry->getKey(),
        );
    }

    private function importAttachment(SupportWebhookOutbox $entry): void
    {
        if ($entry->attachment === null) {
            throw new RuntimeException('Der Support-Outbox-Anhang fehlt.');
        }
        if (in_array($entry->attachment->scan_result, [
            'clean',
            'infected',
            'rejected',
            'scan_failed',
        ], true)) {
            SupportTicketMessageCreated::dispatch(
                $entry->attachment->message()->with('files')->firstOrFail(),
                'support-outbox-'.$entry->getKey().'-terminal',
            );

            return;
        }

        $this->bus->dispatchNow(
            new ImportZammadAttachment($entry->attachment->getKey()),
        );
    }

    /**
     * Database notifications are exactly idempotent through their stable
     * primary key. Broadcast consumers deduplicate the same stable ID. Mail
     * and web push intentionally remain at-least-once across a process crash
     * after the remote channel accepted the send but before processed_at.
     *
     * @param  class-string|string  $channel
     */
    private function notify(
        SupportWebhookOutbox $entry,
        string $channel,
    ): void {
        if (
            $entry->recipient_id === null
            && $entry->notification_id !== null
        ) {
            return;
        }

        if ($entry->recipient === null || $entry->notification_id === null) {
            throw new RuntimeException('Der Support-Outbox-Empfänger fehlt.');
        }

        if (
            $channel === 'database'
            && DB::table('notifications')->where('id', $entry->notification_id)->exists()
        ) {
            return;
        }

        $ticket = $entry->message->supportTicket;
        $notification = new ActivityNotification([
            'event' => 'support.ticket_replied',
            'translations' => [
                'de' => [
                    'title' => 'Antwort vom Erin-Support',
                    'message' => sprintf(
                        'Dein Ticket %s wurde beantwortet.',
                        $ticket->number,
                    ),
                ],
                'en' => [
                    'title' => 'Reply from Erin support',
                    'message' => sprintf(
                        'Your ticket %s has been answered.',
                        $ticket->number,
                    ),
                ],
            ],
            'url' => route('support.index', ['ticket' => $ticket->getKey()]),
            'ticket_id' => $ticket->getKey(),
        ]);
        $notification->id = $entry->notification_id;
        if (! in_array($channel, $notification->via($entry->recipient), true)) {
            return;
        }

        Notification::sendNow($entry->recipient, $notification, [$channel]);
    }
}
