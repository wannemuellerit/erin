<?php

namespace App\Console\Commands;

use App\Contracts\PayoutProvider;
use App\Models\ReferralPayoutIntent;
use Illuminate\Console\Command;

class ReconcileReferralPayouts extends Command
{
    protected $signature = 'erin:payouts:reconcile {--limit=100}';

    protected $description = 'Reconcile submitted referral payouts with the configured provider';

    public function handle(PayoutProvider $provider): int
    {
        $processed = 0;
        ReferralPayoutIntent::query()->with('referral')->where('status', 'submitted')->oldest('submitted_at')
            ->limit(max(1, min(500, (int) $this->option('limit'))))->get()
            ->each(function (ReferralPayoutIntent $intent) use ($provider, &$processed): void {
                if ($intent->provider_reference === null) {
                    return;
                }
                $result = $provider->retrieve($intent->provider_reference);
                if ($result['status'] === 'paid') {
                    $intent->update(['status' => 'paid', 'paid_at' => now(), 'failure_code' => null]);
                    $intent->referral->update(['status' => 'paid', 'paid_at' => now()]);
                } elseif ($result['status'] === 'failed') {
                    $intent->update(['status' => 'failed', 'failed_at' => now(), 'failure_code' => $result['failure_code'] ?? 'reconcile_failed']);
                }
                $processed++;
            });
        $this->info(json_encode(['processed' => $processed], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
