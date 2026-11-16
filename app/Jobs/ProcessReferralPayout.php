<?php

namespace App\Jobs;

use App\Contracts\PayoutProvider;
use App\Models\ReferralPayoutIntent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessReferralPayout implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 1800, 7200];

    public function __construct(public readonly int $intentId)
    {
        $this->onQueue('payments');
    }

    public function handle(PayoutProvider $provider): void
    {
        try {
            DB::transaction(function () use ($provider): void {
                /** @var ReferralPayoutIntent $intent */
                $intent = ReferralPayoutIntent::query()->with(['payoutAccount', 'referral'])->lockForUpdate()->findOrFail($this->intentId);
                if (in_array($intent->status, ['submitted', 'paid'], true)) {
                    return;
                }
                abort_unless($intent->status === 'approved' && $intent->approved_at !== null, 409);
                $account = $intent->payoutAccount;
                abort_if($account === null || ! $account->isPayable(), 409);
                $result = $provider->submit($intent, $account->external_account_id, $intent->idempotency_key);
                $intent->update(['status' => $result['status'], 'provider_reference' => $result['reference'], 'submitted_at' => now(), 'paid_at' => $result['status'] === 'paid' ? now() : null]);
                if ($result['status'] === 'paid') {
                    $intent->referral->update(['status' => 'paid', 'paid_at' => now()]);
                }
            }, 3);
        } catch (Throwable $exception) {
            ReferralPayoutIntent::query()->whereKey($this->intentId)->whereNotIn('status', ['submitted', 'paid'])
                ->update(['status' => 'retrying', 'failure_code' => class_basename($exception), 'failed_at' => now(), 'updated_at' => now()]);
            throw $exception;
        }
    }
}
