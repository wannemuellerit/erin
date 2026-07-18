<?php

namespace App\Console\Commands;

use App\Models\BillingChangeIntent;
use App\Services\Billing\BillingPlanChangeManager;
use Illuminate\Console\Command;

class ReconcileStripeBillingChangesCommand extends Command
{
    protected $signature = 'erin:stripe:reconcile-billing
        {--limit=50 : Maximale Anzahl offener Änderungen}';

    protected $description = 'Gleicht langlebige Stripe-Tarifwechsel-Intents idempotent erneut ab.';

    public function handle(BillingPlanChangeManager $changes): int
    {
        $limit = filter_var(
            $this->option('limit'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 500]],
        );
        if (! is_int($limit)) {
            $this->error('--limit muss zwischen 1 und 500 liegen.');

            return self::FAILURE;
        }

        $intents = BillingChangeIntent::query()
            ->whereIn('status', ['pending', 'applying', 'reconcile'])
            ->oldest('updated_at')
            ->limit($limit)
            ->get();
        $remaining = 0;

        foreach ($intents as $intent) {
            if ($changes->reconcile($intent)->status !== 'applied') {
                $remaining++;
            }
        }
        $manualReviews = BillingChangeIntent::query()
            ->where('status', 'manual_review')
            ->count();

        $this->info(sprintf(
            '%d Billing-Intent(s) geprüft, %d weiterhin offen, %d benötigen eine manuelle Prüfung.',
            $intents->count(),
            $remaining,
            $manualReviews,
        ));

        return $remaining === 0 && $manualReviews === 0
            ? self::SUCCESS
            : self::FAILURE;
    }
}
