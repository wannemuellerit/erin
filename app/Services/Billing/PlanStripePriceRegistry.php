<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\PlanStripePrice;
use App\Models\StripeAddonPrice;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;

class PlanStripePriceRegistry
{
    public function record(Plan $plan, string $source): ?PlanStripePrice
    {
        if (
            ! is_string($plan->stripe_product_id)
            || $plan->stripe_product_id === ''
            || ! is_string($plan->stripe_price_id)
            || $plan->stripe_price_id === ''
            || ! is_int($plan->price_cents)
            || $plan->price_cents < 1
            || ! is_int($plan->term_months)
            || $plan->term_months < 1
        ) {
            return null;
        }

        if (mb_strlen($source) > 40 || $source === '') {
            throw new LogicException(
                'Die Quelle einer Stripe-Preisversion ist ungültig.',
            );
        }

        try {
            return Cache::lock($this->roleLockKey(
                (string) $plan->stripe_price_id,
            ), 30)->block(10, function () use (
                $plan,
                $source,
            ): PlanStripePrice {
                return DB::transaction(function () use (
                    $plan,
                    $source,
                ): PlanStripePrice {
                    /** @var Plan $lockedPlan */
                    $lockedPlan = Plan::query()
                        ->lockForUpdate()
                        ->findOrFail($plan->getKey());
                    if (
                        StripeAddonPrice::query()
                            ->where(
                                'stripe_price_id',
                                $lockedPlan->stripe_price_id,
                            )
                            ->exists()
                        || in_array(
                            $lockedPlan->stripe_price_id,
                            [
                                config('services.stripe.seat_price_id'),
                                config('services.stripe.visa_price_id'),
                            ],
                            true,
                        )
                    ) {
                        throw new LogicException(
                            'Eine Stripe-Price darf nicht zugleich als Basispaket und Add-on registriert werden.',
                        );
                    }

                    $versionHash = $this->versionHash($lockedPlan);
                    $now = now();
                    /** @var PlanStripePrice|null $existing */
                    $existing = PlanStripePrice::query()
                        ->where(
                            'stripe_price_id',
                            $lockedPlan->stripe_price_id,
                        )
                        ->lockForUpdate()
                        ->first();
                    if (
                        $existing !== null
                        && (
                            $existing->plan_id !== $lockedPlan->getKey()
                            || $existing->stripe_product_id
                                !== $lockedPlan->stripe_product_id
                            || $existing->price_cents
                                !== $lockedPlan->price_cents
                            || $existing->currency
                                !== strtoupper($lockedPlan->currency)
                            || $existing->term_months
                                !== $lockedPlan->term_months
                            || $existing->version_hash !== $versionHash
                        )
                    ) {
                        throw new LogicException(
                            'Eine Stripe-Price-Version ist unveränderlich und darf keinem anderen Paket oder Betrag zugeordnet werden.',
                        );
                    }

                    PlanStripePrice::query()
                        ->where('plan_id', $lockedPlan->getKey())
                        ->where('is_current', true)
                        ->where(
                            'stripe_price_id',
                            '!=',
                            $lockedPlan->stripe_price_id,
                        )
                        ->update([
                            'is_current' => false,
                            'retired_at' => $now,
                            'updated_at' => $now,
                        ]);

                    if ($existing !== null) {
                        $existing->forceFill([
                            'is_current' => true,
                            'retired_at' => null,
                        ])->save();

                        return $existing;
                    }

                    /** @var PlanStripePrice $version */
                    $version = PlanStripePrice::query()->create([
                        'stripe_price_id' => $lockedPlan->stripe_price_id,
                        'plan_id' => $lockedPlan->getKey(),
                        'stripe_product_id' => $lockedPlan->stripe_product_id,
                        'price_cents' => $lockedPlan->price_cents,
                        'currency' => strtoupper($lockedPlan->currency),
                        'term_months' => $lockedPlan->term_months,
                        'version_hash' => $versionHash,
                        'source' => $source,
                        'is_current' => true,
                        'activated_at' => $now,
                        'retired_at' => null,
                    ]);

                    return $version;
                }, 3);
            });
        } catch (LockTimeoutException $exception) {
            throw new LogicException(
                'Die Stripe-Price-Rolle wird bereits parallel registriert.',
                previous: $exception,
            );
        }
    }

    private function versionHash(Plan $plan): string
    {
        return hash('sha256', implode('|', [
            $plan->stripe_product_id,
            $plan->stripe_price_id,
            $plan->price_cents,
            strtoupper($plan->currency),
            $plan->term_months,
        ]));
    }

    private function roleLockKey(string $priceId): string
    {
        return 'stripe-price-role:'.hash('sha256', $priceId);
    }
}
