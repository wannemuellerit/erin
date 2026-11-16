<?php

namespace Tests\Support;

use App\Contracts\PayoutProvider;
use App\Models\ReferralPayoutIntent;
use RuntimeException;

final class ErinPayoutProvider implements PayoutProvider
{
    /** @var list<string> */
    public array $submissions = [];

    public string $status = 'submitted';

    public bool $fail = false;

    public function submit(ReferralPayoutIntent $intent, string $externalAccountId, string $idempotencyKey): array
    {
        $this->submissions[] = $idempotencyKey;
        if ($this->fail) {
            throw new RuntimeException('secret provider response');
        }

        return ['reference' => 'payout-'.$intent->public_id, 'status' => $this->status];
    }

    public function retrieve(string $providerReference): array
    {
        return ['status' => $this->status];
    }
}
