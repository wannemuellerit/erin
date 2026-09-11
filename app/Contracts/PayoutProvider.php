<?php

namespace App\Contracts;

use App\Models\ReferralPayoutIntent;

interface PayoutProvider
{
    /** @return array{reference: string, status: 'submitted'|'paid'} */
    public function submit(ReferralPayoutIntent $intent, string $externalAccountId, string $idempotencyKey): array;

    /** @return array{status: 'submitted'|'paid'|'failed', failure_code?: string} */
    public function retrieve(string $providerReference): array;
}
