<?php

namespace App\Services\SupportChat;

final class SupportChatSafety
{
    public function isAdversarial(string $message): bool
    {
        $normalized = mb_strtolower($message);

        foreach ([
            'ignore previous',
            'ignore all previous',
            'ignoriere vorherige',
            'system prompt',
            'developer message',
            'jailbreak',
            'zeige mir dein prompt',
            'reveal your prompt',
            'api key',
            'access token',
        ] as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public function requestsStateMutation(string $message): bool
    {
        $normalized = mb_strtolower($message);

        return preg_match(
            '/\b(ablehnen|ablehne|akzeptieren|akzeptiere|einstellen|stelle ein|kündigen|kündige|sperren|sperre|entsperren|entsperre|erstatten|erstatte|bezahlen|bezahle|status.{0,20}(ändern|ändere)|ändere.{0,20}status|approve|reject|hire|cancel|refund|block|unblock|change.{0,20}status)\b/u',
            $normalized,
        ) === 1;
    }

    public function redact(string $value): string
    {
        $redacted = preg_replace('/\b(?:\d[ -]*?){13,19}\b/', '[ZAHLUNGSDATEN ENTFERNT]', $value) ?? $value;
        $redacted = preg_replace('/\b(?:sk|pk|api)[_-][A-Za-z0-9_-]{12,}\b/i', '[TOKEN ENTFERNT]', $redacted) ?? $redacted;
        $redacted = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[E-MAIL ENTFERNT]', $redacted) ?? $redacted;
        $redacted = preg_replace('/(?<!\d)(?:\+?\d[\d ()\/-]{7,}\d)(?!\d)/', '[TELEFON ENTFERNT]', $redacted) ?? $redacted;
        $redacted = preg_replace('/(?i)(password|passwort|token|secret|pin|tan)\s*[:=]\s*\S+/', '$1=[ENTFERNT]', $redacted) ?? $redacted;

        return trim($redacted);
    }
}
