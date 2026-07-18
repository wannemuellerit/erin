<?php

namespace App\Services\Ticketing;

use App\Models\SupportTicketMessage;
use RuntimeException;

final class ZammadMessageMarker
{
    private const PREFIX = 'Erin operation message:';

    public function for(SupportTicketMessage $message): string
    {
        $secret = $this->currentSecret();
        if ($secret === null) {
            throw new RuntimeException(
                'Für Zammad-Nachrichtenmarker fehlt ein Geheimnis mit mindestens 32 Zeichen.',
            );
        }

        return $this->signedMarker($message, $secret);
    }

    /**
     * @return list<string>
     */
    public function verificationMarkersFor(SupportTicketMessage $message): array
    {
        return array_map(
            fn (string $secret): string => $this->signedMarker($message, $secret),
            $this->verificationSecrets(),
        );
    }

    public function messageId(mixed $subject, int $supportTicketId): ?int
    {
        if (
            ! is_string($subject)
            || preg_match(
                '/\A'.preg_quote(self::PREFIX, '/').'([1-9][0-9]{0,18}):([a-f0-9]{64})\z/D',
                $subject,
                $matches,
            ) !== 1
        ) {
            return null;
        }

        $messageId = filter_var(
            $matches[1],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if (! is_int($messageId)) {
            return null;
        }

        $payload = $this->payload($supportTicketId, $messageId);
        foreach ($this->verificationSecrets() as $secret) {
            if (hash_equals(hash_hmac('sha256', $payload, $secret), $matches[2])) {
                return $messageId;
            }
        }

        return null;
    }

    private function payload(int $supportTicketId, int $messageId): string
    {
        return sprintf('erin-zammad-message:v1:%d:%d', $supportTicketId, $messageId);
    }

    private function signedMarker(SupportTicketMessage $message, string $secret): string
    {
        $messageId = $message->getKey();

        return sprintf(
            '%s%d:%s',
            self::PREFIX,
            $messageId,
            hash_hmac('sha256', $this->payload($message->support_ticket_id, $messageId), $secret),
        );
    }

    private function currentSecret(): ?string
    {
        $configured = (string) config('services.zammad.message_marker_secret');
        if (strlen($configured) >= 32) {
            return $configured;
        }

        $webhookSecret = (string) config('services.zammad.webhook_secret');
        if (strlen($webhookSecret) >= 32) {
            return $webhookSecret;
        }

        $appKey = (string) config('app.key');

        return strlen($appKey) >= 32 ? $appKey : null;
    }

    /**
     * @return list<string>
     */
    private function verificationSecrets(): array
    {
        $secrets = array_filter([
            $this->currentSecret(),
            ...config('services.zammad.previous_message_marker_secrets', []),
        ], static fn (mixed $secret): bool => is_string($secret) && strlen($secret) >= 32);

        return array_values(array_unique($secrets));
    }
}
