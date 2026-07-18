<?php

namespace App\Services\Billing;

use Illuminate\Support\Str;

class StripeEnvironment
{
    /**
     * @return 'test'|'live'|'unknown'
     */
    public function secretKeyMode(): string
    {
        $secret = (string) config('cashier.secret');

        return match (true) {
            Str::startsWith($secret, 'sk_test_') => 'test',
            Str::startsWith($secret, 'sk_live_') => 'live',
            default => 'unknown',
        };
    }

    public function acceptsEventMode(mixed $livemode): bool
    {
        if (! is_bool($livemode)) {
            return false;
        }

        return match ($this->secretKeyMode()) {
            'test' => $livemode === false,
            'live' => $livemode === true,
            default => false,
        };
    }
}
