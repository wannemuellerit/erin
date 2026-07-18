<?php

namespace App\Services\Ticketing;

use Carbon\CarbonInterface;

final class ZammadReconciliationPolicy
{
    public function initialDelaySeconds(): int
    {
        return max(
            1,
            (int) config('services.zammad.reconcile_initial_delay_seconds', 30),
        );
    }

    public function intervalSeconds(): int
    {
        return max(
            1,
            (int) config('services.zammad.reconcile_interval_seconds', 15),
        );
    }

    public function requiredMisses(): int
    {
        return max(
            2,
            min(10, (int) config('services.zammad.reconcile_required_misses', 3)),
        );
    }

    public function secondsUntil(?CarbonInterface $notBefore): int
    {
        if ($notBefore === null || $notBefore->isPast()) {
            return 0;
        }

        return max(
            1,
            (int) ceil(now()->diffInSeconds($notBefore, false)),
        );
    }
}
