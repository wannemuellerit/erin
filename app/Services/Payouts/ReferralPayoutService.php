<?php

namespace App\Services\Payouts;

use App\Enums\ReferralStatus;
use App\Jobs\ProcessReferralPayout;
use App\Models\PayoutAccount;
use App\Models\Referral;
use App\Models\ReferralPayoutIntent;
use App\Models\User;

final class ReferralPayoutService
{
    public function __construct(private readonly ReferralFraudAssessor $fraud) {}

    public function createForApprovedReferral(Referral $referral, User $approver): ReferralPayoutIntent
    {
        abort_unless($referral->status === ReferralStatus::Approved && $referral->hold_until?->isPast(), 422);
        $referral->loadMissing('referralCode');
        $account = PayoutAccount::query()->where('user_id', $referral->referralCode->user_id)->whereNull('disabled_at')->latest()->first();
        $assessment = $this->fraud->assess($referral, $account);
        $reviewThreshold = (int) config('services.payouts.fraud_review_score', 50);
        $status = $assessment['score'] >= $reviewThreshold ? 'manual_review' : ($account?->isPayable() ? 'approved' : 'awaiting_account');

        $intent = ReferralPayoutIntent::query()->firstOrCreate(
            ['referral_id' => $referral->getKey()],
            ['payout_account_id' => $account?->getKey(), 'amount_cents' => $referral->commission_cents,
                'currency_code' => $referral->currency, 'status' => $status, 'fraud_score' => $assessment['score'],
                'fraud_signals' => $assessment['signals'], 'approved_by' => $approver->getKey(), 'approved_at' => now(),
                'idempotency_key' => hash('sha256', 'referral-payout:'.$referral->getKey().':'.$referral->commission_cents.':'.$referral->currency)],
        );
        if ($intent->wasRecentlyCreated && $intent->status === 'approved') {
            ProcessReferralPayout::dispatch($intent->getKey())->afterCommit();
        }

        return $intent;
    }

    public function attachAccount(PayoutAccount $account): int
    {
        $intents = ReferralPayoutIntent::query()->where('status', 'awaiting_account')
            ->whereHas('referral.referralCode', fn ($query) => $query->where('user_id', $account->user_id))->get();
        foreach ($intents as $intent) {
            $assessment = $this->fraud->assess($intent->referral, $account);
            $status = $assessment['score'] >= (int) config('services.payouts.fraud_review_score', 50) ? 'manual_review' : 'approved';
            $intent->update(['payout_account_id' => $account->getKey(), 'fraud_score' => $assessment['score'], 'fraud_signals' => $assessment['signals'], 'status' => $status]);
            if ($status === 'approved') {
                ProcessReferralPayout::dispatch($intent->getKey())->afterCommit();
            }
        }

        return $intents->count();
    }
}
