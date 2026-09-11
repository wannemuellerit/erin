<?php

namespace App\Services\Payouts;

use App\Contracts\PayoutProvider;
use App\Models\ReferralPayoutIntent;
use RuntimeException;

final class NullPayoutProvider implements PayoutProvider
{
    public function submit(ReferralPayoutIntent $intent, string $externalAccountId, string $idempotencyKey): array
    {
        throw new RuntimeException('Payout provider is not configured.');
    }

    public function retrieve(string $providerReference): array
    {
        return ['status' => 'failed', 'failure_code' => 'provider_not_configured'];
    }
}
