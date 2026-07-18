<?php

namespace App\Services\Ticketing;

use App\Models\SupportZammadWebhookDelivery;
use App\Models\SupportZammadWebhookInbox;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SupportZammadWebhookInboxRecorder
{
    public const MAX_DELIVERY_ALIASES_PER_PAYLOAD = 8;

    public function record(
        string $deliveryId,
        string $externalTicketId,
        string $rawPayload,
    ): SupportZammadWebhookInbox {
        $payloadSha256 = hash('sha256', $rawPayload);

        return DB::transaction(function () use (
            $deliveryId,
            $externalTicketId,
            $rawPayload,
            $payloadSha256,
        ): SupportZammadWebhookInbox {
            $delivery = SupportZammadWebhookDelivery::query()
                ->where('delivery_id', $deliveryId)
                ->lockForUpdate()
                ->first();
            if ($delivery !== null) {
                $deliveryEntry = SupportZammadWebhookInbox::query()
                    ->lockForUpdate()
                    ->findOrFail(
                        $delivery->support_zammad_webhook_inbox_id,
                    );
                $this->assertPayloadIdentity(
                    $deliveryEntry,
                    $externalTicketId,
                    $rawPayload,
                    $payloadSha256,
                    'Eine Zammad-Delivery-ID wurde mit widersprüchlichem Inhalt wiederverwendet.',
                );

                return $deliveryEntry;
            }

            $entry = SupportZammadWebhookInbox::query()->createOrFirst(
                ['payload_sha256' => $payloadSha256],
                [
                    'delivery_id' => $deliveryId,
                    'external_ticket_id' => $externalTicketId,
                    'raw_payload' => $rawPayload,
                    'attempts' => 0,
                    'available_at' => now(),
                ],
            );

            /** @var SupportZammadWebhookInbox $entry */
            $entry = SupportZammadWebhookInbox::query()
                ->lockForUpdate()
                ->findOrFail($entry->getKey());
            $this->assertPayloadIdentity(
                $entry,
                $externalTicketId,
                $rawPayload,
                $payloadSha256,
                'Ein signierter Zammad-Webhook-Hash besitzt widersprüchliche Inhalte.',
            );

            // Die Delivery-ID ist nicht Teil der Zammad-Signatur. Nach einem
            // abgeschlossenen Eintrag darf ein Replay deshalb keine weiteren
            // persistenten Aliase erzeugen.
            if (
                $entry->processed_at !== null
                || $entry->terminal_at !== null
            ) {
                return $entry;
            }

            $aliasCount = SupportZammadWebhookDelivery::query()
                ->where(
                    'support_zammad_webhook_inbox_id',
                    $entry->getKey(),
                )
                ->count();
            if ($aliasCount >= self::MAX_DELIVERY_ALIASES_PER_PAYLOAD) {
                throw new RuntimeException(
                    'Die maximale Anzahl von Zammad-Delivery-Aliasen für diesen Webhook wurde erreicht.',
                );
            }

            $delivery = SupportZammadWebhookDelivery::query()->createOrFirst(
                ['delivery_id' => $deliveryId],
                [
                    'support_zammad_webhook_inbox_id' => $entry->getKey(),
                ],
            );
            if (
                (int) $delivery->support_zammad_webhook_inbox_id
                    !== (int) $entry->getKey()
            ) {
                throw new RuntimeException(
                    'Eine Zammad-Delivery-ID wurde einem widersprüchlichen Webhook zugeordnet.',
                );
            }

            return $entry;
        }, 3);
    }

    private function assertPayloadIdentity(
        SupportZammadWebhookInbox $entry,
        string $externalTicketId,
        string $rawPayload,
        string $payloadSha256,
        string $message,
    ): void {
        if (
            ! hash_equals($entry->external_ticket_id, $externalTicketId)
            || ! hash_equals($entry->payload_sha256, $payloadSha256)
            || (
                $entry->raw_payload !== ''
                && ! hash_equals($entry->raw_payload, $rawPayload)
            )
        ) {
            throw new RuntimeException($message);
        }
    }
}
