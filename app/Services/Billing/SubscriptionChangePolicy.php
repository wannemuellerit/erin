<?php

namespace App\Services\Billing;

use App\Models\Plan;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;

class SubscriptionChangePolicy
{
    public function isUpgrade(Plan $current, Plan $target): bool
    {
        if ($current->is($target)) {
            throw new DomainException(__('Dieses Paket ist bereits aktiv.'));
        }

        if (
            ! $target->is_active
            || $target->is_enterprise
            || blank($target->stripe_price_id)
            || $target->price_cents === null
        ) {
            throw new DomainException(__('Dieses Paket kann nicht direkt gebucht werden.'));
        }

        if ($current->price_cents === null) {
            throw new DomainException(__('Das aktuelle Paket besitzt keinen vergleichbaren Preis.'));
        }

        return $target->price_cents > $current->price_cents;
    }

    public function cancellationDate(
        CarbonInterface $renewsAt,
        int $termMonths,
        CarbonInterface $now,
    ): CarbonImmutable {
        if ($termMonths < 1 || $termMonths > 12) {
            throw new DomainException(__('Die Paketlaufzeit muss zwischen 1 und 12 Monaten liegen.'));
        }

        $renewal = CarbonImmutable::instance($renewsAt);
        $currentTime = CarbonImmutable::instance($now);

        return $currentTime->lte($renewal->subDays(14))
            ? $renewal
            : $renewal->addMonthsNoOverflow($termMonths);
    }
}
