<?php

namespace App\Services\Ticketing;

final class ZammadWebhookSignature
{
    public function create(string $payload, string $secret): string
    {
        return 'sha1='.hash_hmac('sha1', $payload, $secret);
    }

    public function isValid(string $payload, string $signature, string $secret): bool
    {
        if (
            $secret === ''
            || preg_match('/^sha1=[a-f0-9]{40}$/', $signature) !== 1
        ) {
            return false;
        }

        return hash_equals($this->create($payload, $secret), $signature);
    }
}
