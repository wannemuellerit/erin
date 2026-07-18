<?php

namespace App\Services\Ticketing;

use App\Models\SupportTicketMessage;
use App\Models\SupportWebhookOutbox;
use RuntimeException;

class SupportWebhookOutboxRecorder
{
    /**
     * @param  list<int>  $attachmentIds
     */
    public function record(
        SupportTicketMessage $message,
        array $attachmentIds,
        bool $notifyRequester,
    ): void {
        $this->recordEffect($message, 'broadcast');

        foreach ($attachmentIds as $attachmentId) {
            $this->recordEffect(
                $message,
                'attachment_import',
                attachmentId: $attachmentId,
            );
        }

        if ($notifyRequester) {
            foreach ([
                'notification_database',
                'notification_broadcast',
                'notification_mail',
                'notification_push',
            ] as $effect) {
                $this->recordEffect(
                    $message,
                    $effect,
                    recipientId: $message->supportTicket->requester_id,
                );
            }
        }
    }

    private function recordEffect(
        SupportTicketMessage $message,
        string $effect,
        ?int $attachmentId = null,
        ?int $recipientId = null,
    ): void {
        $identity = implode(':', array_filter([
            'zammad',
            'message',
            (string) $message->getKey(),
            $effect,
            $attachmentId === null ? null : (string) $attachmentId,
            $recipientId === null ? null : (string) $recipientId,
        ], static fn (?string $value): bool => $value !== null));
        $deduplicationKey = hash('sha256', $identity);

        $notificationId = str_starts_with($effect, 'notification_')
            ? $this->notificationId($message, $recipientId)
            : null;
        $identityAttributes = [
            'support_ticket_message_id' => $message->getKey(),
            'support_ticket_attachment_id' => $attachmentId,
            'recipient_id' => $recipientId,
            'effect' => $effect,
            'notification_id' => $notificationId,
        ];
        $entry = SupportWebhookOutbox::query()->createOrFirst(
            ['deduplication_key' => $deduplicationKey],
            [
                ...$identityAttributes,
                'attempts' => 0,
                'available_at' => now(),
            ],
        );

        foreach ($identityAttributes as $attribute => $expected) {
            if ($entry->getAttribute($attribute) !== $expected) {
                throw new RuntimeException(
                    'Ein Support-Outbox-Deduplizierungsschlüssel besitzt widersprüchliche Zustelldaten.',
                );
            }
        }
    }

    private function notificationId(
        SupportTicketMessage $message,
        ?int $recipientId,
    ): string {
        return $this->uuidFromHash(hash('sha256', implode(':', [
            'zammad',
            'message',
            (string) $message->getKey(),
            'notification',
            (string) $recipientId,
        ])));
    }

    private function uuidFromHash(string $hash): string
    {
        return sprintf(
            '%s-%s-5%s-a%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            substr($hash, 17, 3),
            substr($hash, 20, 12),
        );
    }
}
