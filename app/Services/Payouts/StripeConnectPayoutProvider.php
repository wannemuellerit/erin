<?php

namespace App\Services\Payouts;

use App\Contracts\PayoutProvider;
use App\Models\ReferralPayoutIntent;
use Stripe\StripeClient;

final class StripeConnectPayoutProvider implements PayoutProvider
{
    public function submit(ReferralPayoutIntent $intent, string $externalAccountId, string $idempotencyKey): array
    {
        $transfer = (new StripeClient((string) config('cashier.secret')))->transfers->create([
            'amount' => $intent->amount_cents, 'currency' => strtolower($intent->currency_code),
            'destination' => $externalAccountId, 'metadata' => ['payout_intent' => $intent->public_id, 'referral_id' => (string) $intent->referral_id],
        ], ['idempotency_key' => $idempotencyKey]);

        return ['reference' => $transfer->id, 'status' => 'submitted'];
    }

    public function retrieve(string $providerReference): array
    {
        $transfer = (new StripeClient((string) config('cashier.secret')))->transfers->retrieve($providerReference);

        return ['status' => $transfer->reversed ? 'failed' : 'submitted', 'failure_code' => $transfer->reversed ? 'reversed' : null];
    }
}
