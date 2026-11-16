<?php

namespace App\Services\Payouts;

use App\Models\PayoutAccount;
use App\Models\Referral;
use App\Models\ReferralPayoutIntent;

final class ReferralFraudAssessor
{
    /** @return array{score: int, signals: list<string>} */
    public function assess(Referral $referral, ?PayoutAccount $account): array
    {
        $signals = [];
        $score = 0;
        $referral->loadMissing('referralCode');
        if ($referral->referred_user_id !== null && $referral->referralCode->user_id === $referral->referred_user_id) {
            $signals[] = 'self_referral';
            $score += 100;
        }
        if ($account !== null && PayoutAccount::query()->where('external_account_hash', $account->external_account_hash)->where('user_id', '!=', $account->user_id)->exists()) {
            $signals[] = 'shared_payout_account';
            $score += 60;
        }
        if ($referral->commission_cents > (int) config('services.payouts.manual_review_amount_cents', 50000)) {
            $signals[] = 'high_amount';
            $score += 30;
        }
        $recent = ReferralPayoutIntent::query()->whereHas('referral.referralCode', fn ($query) => $query->where('user_id', $referral->referralCode->user_id))
            ->where('created_at', '>=', now()->subDay())->count();
        if ($recent >= 5) {
            $signals[] = 'high_velocity';
            $score += 40;
        }

        return ['score' => min(100, $score), 'signals' => $signals];
    }
}
